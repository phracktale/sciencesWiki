<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Entity\Assessment;
use Analyses\Entity\AssessmentCriterion;
use Analyses\Entity\Evidence;
use Analyses\Ontology\StudyDesign;
use Analyses\Router\RouterEngine;
use Analyses\Sdk\CorpusPort;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orchestrateur (SPECS §12) : corpus → empreinte → routage → exécution des référentiels
 * applicables → résultat canonique persisté (analys_*). Le résultat est indépendant du
 * rôle ; la restitution par rôle se fait à la lecture.
 */
final class AnalysisOrchestrator
{
    public function __construct(
        private readonly CorpusPort $corpus,
        private readonly StudyFingerprinter $fingerprinter,
        private readonly RouterEngine $router,
        private readonly AnalyzerRegistry $analyzers,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'ANALYS_MODEL')]
        private readonly string $model = 'glm-5.2:cloud',
        #[Autowire(env: 'ANALYS_HUMAN_REVIEW_THRESHOLD')]
        private readonly float $humanReviewThreshold = 0.75,
    ) {
    }

    public function run(string $documentRef, ?string $designOverride = null): Assessment
    {
        $pub = $this->corpus->findPublication($documentRef);
        if (null === $pub) {
            throw new PublicationNotFound(\sprintf('Publication introuvable : %s', $documentRef));
        }

        $pubId = (int) $pub['id'];
        $fulltext = $this->corpus->fulltext($pubId);

        // 1) Empreinte d'étude.
        $fingerprint = $this->fingerprinter->fingerprint($pub, $fulltext);
        $design = StudyDesign::tryFrom((string) $fingerprint['study_design']) ?? StudyDesign::Unknown;

        // Override manuel du plan d'étude (validation humaine, SPECS §13) : on conserve
        // l'empreinte automatique mais on route sur le plan choisi, en exigeant une revue.
        $overridden = false;
        if (null !== $designOverride && null !== ($forced = StudyDesign::tryFrom($designOverride))) {
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

        $assessment = (new Assessment($documentRef))
            ->setPrimaryDesign($design->value)
            ->setRoutingConfidence((float) $fingerprint['confidence'])
            ->setFingerprint($fingerprint)
            ->setPlan($plan)
            ->setModel($this->model);
        $this->em->persist($assessment);

        // Validation humaine requise si routage incertain, texte indisponible, ou override (SPECS §14).
        $humanReview = $overridden
            || (float) $fingerprint['confidence'] < $this->humanReviewThreshold
            || false === $fingerprint['fulltext_available'];

        // 3) Exécution des référentiels principaux dont un analyseur est enregistré.
        foreach ($plan['primary_frameworks'] as $frameworkId) {
            $analyzer = $this->analyzers->get($frameworkId);
            if (null === $analyzer) {
                continue;
            }

            $result = $analyzer->analyze($fulltext, $pub);
            foreach ($result['criteria'] as $c) {
                $criterion = (new AssessmentCriterion($assessment->getId(), $frameworkId, (string) $c['criterion_id'], (string) $c['question']))
                    ->setDimension($c['dimension'] ?? null)
                    ->setAnswer((string) $c['answer'])
                    ->setEvidenceType($c['evidence_type'] ?? null)
                    ->setConfidence($c['confidence'] ?? null)
                    ->setAnalysis($c['analysis'] ?? null)
                    ->setRequiresHumanReview((bool) ($c['requires_human_review'] ?? false));
                $this->em->persist($criterion);

                $quote = trim((string) ($c['quote'] ?? ''));
                if ('' !== $quote) {
                    $evidence = (new Evidence($assessment->getId(), $quote, (string) ($c['evidence_type'] ?? 'explicit_quote')))
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

        return $assessment;
    }
}
