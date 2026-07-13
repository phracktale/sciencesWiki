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
use Symfony\Component\Messenger\MessageBusInterface;
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
        private readonly AnalysisNotifier $notifier,
        private readonly \Analyses\Service\SettingsService $settings,
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
    ) {
    }

    /**
     * Crée l'évaluation (statut « queued ») et publie le message d'exécution.
     * Vérifie l'existence de la publication tout de suite (erreur 404 immédiate).
     */
    public function queue(string $documentRef, ?string $designOverride = null, ?string $requestedBy = null): Assessment
    {
        $pub = $this->corpus->findPublication($documentRef);
        if (null === $pub) {
            throw new PublicationNotFound(\sprintf('Publication introuvable : %s', $documentRef));
        }

        $override = null !== $designOverride && null !== StudyDesign::tryFrom($designOverride) ? $designOverride : null;
        $treePath = $this->corpus->treePath((int) $pub['id']);

        $assessment = (new Assessment($documentRef))
            ->setStatus('queued')
            ->setRequestedBy($requestedBy)
            ->setDesignOverride($override)
            ->setDocumentTitle(\is_string($pub['title'] ?? null) ? (string) $pub['title'] : null)
            ->setDocumentDoi(\is_string($pub['doi'] ?? null) ? (string) $pub['doi'] : null)
            ->setTreePath([] !== $treePath ? $treePath : null);
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
            $this->markFailed($assessmentId);

            throw $e; // laisse Messenger réessayer selon la stratégie configurée
        }

        $this->notifier->notify($assessment);
    }

    /**
     * Marque l'évaluation en échec de façon ROBUSTE : si une erreur a fermé l'EntityManager
     * (ex. erreur SQL au flush), on le réinitialise pour pouvoir écrire le statut « failed »
     * sans propager une seconde exception « EntityManager is closed ».
     */
    private function markFailed(Ulid $assessmentId): void
    {
        try {
            if (!$this->em->isOpen()) {
                $this->doctrine->resetManager();
            }
            $em = $this->doctrine->getManager();
            $a = $em->find(Assessment::class, $assessmentId);
            if (null !== $a) {
                $a->setStatus('failed');
                $em->flush();
            }
        } catch (\Throwable) {
            // best-effort : ne masque pas l'erreur d'origine.
        }
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
        $succeeded = 0;
        $failedFrameworks = [];
        foreach ($frameworkIds as $frameworkId) {
            $analyzer = $this->analyzers->get($frameworkId) ?? $this->analyzers->get($this->frameworkFamily($frameworkId));
            if (null === $analyzer || isset($ranAnalyzers[$analyzer->frameworkId()])) {
                continue;
            }
            if (null !== $enabled && !\in_array($analyzer->frameworkId(), $enabled, true)) {
                continue; // référentiel désactivé par l'admin
            }
            $ranAnalyzers[$analyzer->frameworkId()] = true;

            // L'appel LLM (long) est fait AVANT toute persistance : s'il échoue (souvent un
            // timeout), rien n'est écrit pour ce référentiel et on passe au suivant. Chaque
            // référentiel est ensuite flushé SÉPARÉMENT → les résultats déjà obtenus survivent
            // même si un référentiel ultérieur échoue.
            try {
                $result = $analyzer->analyze($fulltext, $pub);
                foreach ($result['criteria'] as $c) {
                    // Champs bornés (VARCHAR) alimentés par le LLM : troncature défensive.
                    $criterion = (new AssessmentCriterion($assessment->getId(), $analyzer->frameworkId(), $this->clamp((string) $c['criterion_id'], 64) ?? '', (string) $c['question']))
                        ->setDimension($this->clamp($c['dimension'] ?? null, 96))
                        ->setAnswer($this->clamp((string) $c['answer'], 24) ?? 'unclear')
                        ->setVerdict($this->clamp($c['verdict'] ?? null, 96))
                        ->setExpected($c['expected'] ?? null)
                        ->setEvidenceFound($c['evidence_found'] ?? null)
                        ->setAnalysis($c['analysis'] ?? null)
                        ->setLimitations($c['limitations'] ?? null)
                        ->setEvidenceType($this->clamp($c['evidence_type'] ?? null, 48))
                        ->setOverallEvidenceType($this->clamp($c['overall_evidence_type'] ?? null, 64))
                        ->setConfidence($this->clamp($c['confidence'] ?? null, 16))
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

                $this->em->flush();
                ++$succeeded;
            } catch (\Throwable) {
                $failedFrameworks[] = $analyzer->frameworkId();
                $humanReview = true;
            }
        }

        // Aucun référentiel abouti → échec réel (laisse Messenger réessayer).
        if (0 === $succeeded) {
            throw new \RuntimeException(\sprintf('Aucun référentiel exécuté (échecs : %s).', implode(', ', $failedFrameworks) ?: 'aucun analyseur'));
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
                        (new Evidence($assessmentId, $quote, $this->clamp($type, 48) ?? 'explicit_quote'))
                            ->setCriterionId($this->clamp($criterionId, 64))
                            ->setConfidence($this->clamp($confidence, 16))
                            ->setSection($this->clamp($e['section'] ?? null, 96))
                            ->setSourceType($this->clamp($e['source_type'] ?? null, 16)),
                    );
                }
            }

            return;
        }

        $quote = trim((string) ($c['quote'] ?? ''));
        if ('' !== $quote && 'explicit_quote' === ($c['evidence_type'] ?? '')) {
            $this->em->persist(
                (new Evidence($assessmentId, $quote, 'explicit_quote'))
                    ->setCriterionId($this->clamp($criterionId, 64))
                    ->setConfidence($this->clamp($confidence, 16)),
            );
        }
    }

    /** Tronque une valeur destinée à une colonne VARCHAR bornée (null-safe). */
    private function clamp(mixed $v, int $max): ?string
    {
        if (null === $v || '' === $v) {
            return null;
        }
        $s = (string) $v;

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
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
}
