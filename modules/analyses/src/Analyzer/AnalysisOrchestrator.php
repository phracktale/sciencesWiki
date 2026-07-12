<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Entity\Assessment;
use Analyses\Entity\AssessmentCriterion;
use Analyses\Entity\Evidence;
use Analyses\Message\RunAnalysisMessage;
use Analyses\Ontology\StudyDesign;
use Analyses\Repository\AssessmentRepository;
use Analyses\Router\RouterEngine;
use Analyses\Sdk\CorpusPort;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Ulid;

/**
 * Orchestrateur (SPECS §12) : corpus → empreinte → routage → exécution des référentiels
 * applicables → résultat canonique persisté (analys_*). Deux temps :
 *  - {@see queue()} crée l'évaluation « queued » et publie un message (retour immédiat) ;
 *  - {@see process()} exécute le pipeline (worker) puis notifie le demandeur.
 */
final class AnalysisOrchestrator
{
    public function __construct(
        private readonly CorpusPort $corpus,
        private readonly StudyFingerprinter $fingerprinter,
        private readonly RouterEngine $router,
        private readonly AnalyzerRegistry $analyzers,
        private readonly EntityManagerInterface $em,
        private readonly AssessmentRepository $assessments,
        private readonly MessageBusInterface $bus,
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'ANALYS_MODEL')]
        private readonly string $model = 'glm-5.2:cloud',
        #[Autowire(env: 'ANALYS_HUMAN_REVIEW_THRESHOLD')]
        private readonly float $humanReviewThreshold = 0.75,
        #[Autowire(env: 'default::ANALYS_MAIL_FROM')]
        private readonly ?string $mailFrom = null,
        #[Autowire(env: 'default::MODULE_BASE_URL')]
        private readonly ?string $baseUrl = null,
    ) {
    }

    /**
     * Crée l'évaluation (statut « queued ») et publie le message d'exécution.
     * Vérifie l'existence de la publication tout de suite (erreur 404 immédiate).
     */
    public function queue(string $documentRef, ?string $designOverride = null, ?string $requestedBy = null): Assessment
    {
        if (null === $this->corpus->findPublication($documentRef)) {
            throw new PublicationNotFound(\sprintf('Publication introuvable : %s', $documentRef));
        }

        $override = null !== $designOverride && null !== StudyDesign::tryFrom($designOverride) ? $designOverride : null;

        $assessment = (new Assessment($documentRef))
            ->setStatus('queued')
            ->setRequestedBy($requestedBy)
            ->setDesignOverride($override);
        $this->em->persist($assessment);
        $this->em->flush();

        $this->bus->dispatch(new RunAnalysisMessage((string) $assessment->getId()));

        return $assessment;
    }

    /**
     * Exécute le pipeline pour une évaluation en file (appelé par le worker).
     */
    public function process(Ulid $assessmentId): void
    {
        $assessment = $this->assessments->find($assessmentId);
        if (null === $assessment) {
            return;
        }

        $assessment->setStatus('running');
        $this->em->flush();

        try {
            $this->executePipeline($assessment);
        } catch (\Throwable $e) {
            $assessment->setStatus('failed');
            $this->em->flush();

            throw $e; // laisse Messenger réessayer selon la stratégie configurée
        }

        $this->notify($assessment);
    }

    private function executePipeline(Assessment $assessment): void
    {
        $pub = $this->corpus->findPublication($assessment->getDocumentRef());
        if (null === $pub) {
            $assessment->setStatus('failed');
            $this->em->flush();

            return;
        }

        $fulltext = $this->corpus->fulltext((int) $pub['id']);

        // 1) Empreinte d'étude.
        $fingerprint = $this->fingerprinter->fingerprint($pub, $fulltext);
        $design = StudyDesign::tryFrom((string) $fingerprint['study_design']) ?? StudyDesign::Unknown;

        // Override manuel (validation humaine, SPECS §13).
        $overridden = false;
        if (null !== $assessment->getDesignOverride() && null !== ($forced = StudyDesign::tryFrom($assessment->getDesignOverride()))) {
            $design = $forced;
            $fingerprint['design_override'] = $forced->value;
            $overridden = true;
        }

        // 2) Plan d'analyse composite.
        $plan = $this->router->buildPlan(
            $design,
            $fingerprint['objectives'],
            $fingerprint['domains'],
            $fingerprint['modalities'],
        );

        $assessment
            ->setPrimaryDesign($design->value)
            ->setRoutingConfidence((float) $fingerprint['confidence'])
            ->setFingerprint($fingerprint)
            ->setPlan($plan)
            ->setModel($this->model);

        $humanReview = $overridden
            || (float) $fingerprint['confidence'] < $this->humanReviewThreshold
            || false === $fingerprint['fulltext_available'];

        // 3) Exécution des référentiels (principaux + risque de biais + reporting) dont un
        // analyseur est enregistré. Les identifiants de reporting spécifiques au design
        // (strobe_cross_sectional…) sont résolus vers l'analyseur de famille (strobe).
        $frameworkIds = array_values(array_unique([
            ...$plan['primary_frameworks'],
            ...$plan['risk_of_bias_tools'],
            ...$plan['reporting_frameworks'],
        ]));
        $ranAnalyzers = [];
        foreach ($frameworkIds as $frameworkId) {
            $analyzer = $this->analyzers->get($frameworkId) ?? $this->analyzers->get($this->frameworkFamily($frameworkId));
            if (null === $analyzer || isset($ranAnalyzers[$analyzer->frameworkId()])) {
                continue;
            }
            $ranAnalyzers[$analyzer->frameworkId()] = true;

            $result = $analyzer->analyze($fulltext, $pub);
            foreach ($result['criteria'] as $c) {
                $criterion = (new AssessmentCriterion($assessment->getId(), $analyzer->frameworkId(), (string) $c['criterion_id'], (string) $c['question']))
                    ->setDimension($c['dimension'] ?? null)
                    ->setAnswer((string) $c['answer'])
                    ->setEvidenceType($c['evidence_type'] ?? null)
                    ->setConfidence($c['confidence'] ?? null)
                    ->setAnalysis($c['analysis'] ?? null)
                    ->setRequiresHumanReview((bool) ($c['requires_human_review'] ?? false));
                $this->em->persist($criterion);

                // Preuve persistée UNIQUEMENT si la citation a été vérifiée dans le texte
                // (evidence_type reste « explicit_quote » après le garde-fou d'ancrage).
                // Une citation non vérifiée (« unverified_quote ») n'est pas stockée.
                $quote = trim((string) ($c['quote'] ?? ''));
                if ('' !== $quote && 'explicit_quote' === ($c['evidence_type'] ?? '')) {
                    $evidence = (new Evidence($assessment->getId(), $quote, 'explicit_quote'))
                        ->setCriterionId((string) $c['criterion_id'])
                        ->setConfidence($c['confidence'] ?? null);
                    $this->em->persist($evidence);
                }
            }

            $humanReview = $humanReview || (bool) ($result['overall']['human_review'] ?? false);
        }

        $assessment
            ->setHumanReview($humanReview)
            ->setStatus($humanReview ? 'human_review_required' : 'completed');

        $this->em->flush();
    }

    /**
     * Résout un identifiant de référentiel spécifique au design vers sa famille d'analyseur
     * (ex. strobe_cross_sectional → strobe, consort_cluster → consort).
     */
    private function frameworkFamily(string $id): string
    {
        foreach (['strobe', 'consort', 'prisma'] as $family) {
            if (str_starts_with($id, $family)) {
                return $family;
            }
        }

        return $id;
    }

    /**
     * Notifie le demandeur (port « mailer », graceful). Avec MAILER_DSN=null://null,
     * l'envoi est un no-op ; aucune erreur ne remonte.
     */
    private function notify(Assessment $assessment): void
    {
        $to = $assessment->getRequestedBy();
        if (null === $to || !filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $link = null !== $this->baseUrl && '' !== $this->baseUrl
            ? rtrim($this->baseUrl, '/').'/analyses/'.$assessment->getId()
            : null;

        $body = \sprintf(
            "Votre analyse (%s) est terminée.\nStatut : %s\nPlan : %s\n%s",
            $assessment->getDocumentRef(),
            $assessment->getStatus(),
            $assessment->getPrimaryDesign() ?? 'indéterminé',
            null !== $link ? "Résultat : $link" : '',
        );

        try {
            $this->mailer->send(
                (new Email())
                    ->from($this->mailFrom ?: 'noreply@scienceswiki.eu')
                    ->to($to)
                    ->subject('Analyse SciencesWiki terminée')
                    ->text($body),
            );
        } catch (TransportExceptionInterface) {
            // Notification best-effort : un échec d'envoi ne doit pas invalider l'analyse.
        }
    }
}
