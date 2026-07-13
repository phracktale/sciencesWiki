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
        private readonly \Analyses\Service\SettingsService $settings,
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
            ->setModel($this->settings->analysisModel());

        $humanReview = $overridden
            || (float) $fingerprint['confidence'] < $this->settings->humanReviewThreshold()
            || false === $fingerprint['fulltext_available'];

        // 3) Exécution des référentiels (principaux + risque de biais + reporting) dont un
        // analyseur est enregistré. Les identifiants de reporting spécifiques au design
        // (strobe_cross_sectional…) sont résolus vers l'analyseur de famille (strobe).
        $frameworkIds = array_values(array_unique([
            ...$plan['primary_frameworks'],
            ...$plan['risk_of_bias_tools'],
            ...$plan['reporting_frameworks'],
        ]));
        $enabled = $this->settings->enabledFrameworks(); // null = tous
        $ranAnalyzers = [];
        foreach ($frameworkIds as $frameworkId) {
            $analyzer = $this->analyzers->get($frameworkId) ?? $this->analyzers->get($this->frameworkFamily($frameworkId));
            if (null === $analyzer || isset($ranAnalyzers[$analyzer->frameworkId()])) {
                continue;
            }
            if (null !== $enabled && !\in_array($analyzer->frameworkId(), $enabled, true)) {
                continue; // référentiel désactivé par l'admin
            }
            $ranAnalyzers[$analyzer->frameworkId()] = true;

            $result = $analyzer->analyze($fulltext, $pub);
            foreach ($result['criteria'] as $c) {
                $criterion = (new AssessmentCriterion($assessment->getId(), $analyzer->frameworkId(), (string) $c['criterion_id'], (string) $c['question']))
                    ->setDimension($c['dimension'] ?? null)
                    ->setAnswer((string) $c['answer'])
                    ->setVerdict($c['verdict'] ?? null)
                    ->setExpected($c['expected'] ?? null)
                    ->setEvidenceFound($c['evidence_found'] ?? null)
                    ->setAnalysis($c['analysis'] ?? null)
                    ->setLimitations($c['limitations'] ?? null)
                    ->setEvidenceType($c['evidence_type'] ?? null)
                    ->setOverallEvidenceType($c['overall_evidence_type'] ?? null)
                    ->setConfidence($c['confidence'] ?? null)
                    ->setRequiresVisualCheck((bool) ($c['requires_visual_check'] ?? false))
                    ->setRequiresHumanReview((bool) ($c['requires_human_review'] ?? false));
                $this->em->persist($criterion);

                $this->persistEvidence($assessment->getId(), $c);
            }

            $humanReview = $humanReview || (bool) ($result['overall']['human_review'] ?? false);

            // L'analyseur principal (AXIS) porte l'applicabilité (étape 0) et la réflexion générale.
            if (\array_key_exists('applicable', $result['overall'])) {
                $assessment->setApplicable(null === $result['overall']['applicable'] ? null : (bool) $result['overall']['applicable']);
            }
            if (null !== ($result['overall']['summary'] ?? null) && null === $assessment->getSummary()) {
                $assessment->setSummary((string) $result['overall']['summary']);
            }
        }

        $assessment
            ->setHumanReview($humanReview)
            ->setStatus($humanReview ? 'human_review_required' : 'completed');

        $this->em->flush();
    }

    /**
     * Persiste les preuves d'un critère : tableau riche (AXIS : citations vérifiées avec
     * source_type/section) ou citation « à plat » (autres analyseurs). Seules les citations
     * VÉRIFIÉES (explicit_quote / visual_*) sont stockées ; les « unverified_quote » non.
     *
     * @param array<string, mixed> $c
     */
    private function persistEvidence(\Symfony\Component\Uid\Ulid $assessmentId, array $c): void
    {
        $criterionId = (string) $c['criterion_id'];
        $confidence = $c['confidence'] ?? null;

        if (\is_array($c['evidence'] ?? null) && [] !== $c['evidence']) {
            foreach ($c['evidence'] as $e) {
                $quote = trim((string) ($e['quote'] ?? ''));
                $type = (string) ($e['evidence_type'] ?? '');
                if ('' !== $quote && \in_array($type, ['explicit_quote', 'visual_table', 'visual_figure'], true)) {
                    $this->em->persist(
                        (new Evidence($assessmentId, $quote, $type))
                            ->setCriterionId($criterionId)
                            ->setConfidence($confidence)
                            ->setSection($e['section'] ?? null)
                            ->setSourceType($e['source_type'] ?? null),
                    );
                }
            }

            return;
        }

        $quote = trim((string) ($c['quote'] ?? ''));
        if ('' !== $quote && 'explicit_quote' === ($c['evidence_type'] ?? '')) {
            $this->em->persist(
                (new Evidence($assessmentId, $quote, 'explicit_quote'))
                    ->setCriterionId($criterionId)
                    ->setConfidence($confidence),
            );
        }
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
