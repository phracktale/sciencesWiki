<?php

declare(strict_types=1);

namespace Analyses\Projection;

use Analyses\Entity\Assessment;
use Analyses\Entity\AssessmentCriterion;
use Analyses\Entity\Evidence;

/**
 * Projette un résultat canonique selon un rôle (SPECS §20). Le rôle change la
 * restitution (détail, vocabulaire, mise en avant) — JAMAIS le fond scientifique.
 */
final class ProjectionEngine
{
    public const ROLES = ['researcher', 'methodologist', 'reviewer', 'editor', 'clinician', 'journalist', 'admin'];

    /**
     * @param list<AssessmentCriterion> $criteria
     * @param list<Evidence>            $evidence
     *
     * @return array<string, mixed>
     */
    public function project(Assessment $a, array $criteria, array $evidence, string $role): array
    {
        $role = \in_array($role, self::ROLES, true) ? $role : 'researcher';

        $base = [
            'id' => (string) $a->getId(),
            'role' => $role,
            'document_ref' => $a->getDocumentRef(),
            'status' => $a->getStatus(),
            'design' => $a->getPrimaryDesign(),
            'design_label' => $a->getFingerprint()['design_label'] ?? null,
            'human_review_required' => $a->isHumanReview(),
        ];

        return match ($role) {
            'methodologist' => $base + $this->methodologist($a, $criteria),
            'reviewer' => $base + $this->reviewer($criteria, $evidence),
            'editor' => $base + $this->editor($a, $criteria),
            'clinician' => $base + $this->clinician($a, $criteria),
            'journalist' => $base + $this->journalist($a, $criteria),
            'admin' => $base + $this->admin($a, $criteria),
            default => $base + $this->researcher($a, $criteria, $evidence),
        };
    }

    /** Chercheur : preuves détaillées, tous les critères, empreinte, plan (SPECS §20). */
    private function researcher(Assessment $a, array $criteria, array $evidence): array
    {
        $quotesByCriterion = [];
        foreach ($evidence as $e) {
            $quotesByCriterion[$e->getCriterionId() ?? ''][] = $e->getQuote();
        }

        return [
            'fingerprint' => $a->getFingerprint(),
            'plan' => $a->getPlan(),
            'criteria' => array_map(fn (AssessmentCriterion $c): array => [
                'framework_id' => $c->getFrameworkId(),
                'criterion_id' => $c->getCriterionId(),
                'dimension' => $c->getDimension(),
                'question' => $c->getQuestion(),
                'answer' => $c->getAnswer(),
                'evidence_type' => $c->getEvidenceType(),
                'confidence' => $c->getConfidence(),
                'analysis' => $c->getAnalysis(),
                'quotes' => $quotesByCriterion[$c->getCriterionId()] ?? [],
                'requires_human_review' => $c->requiresHumanReview(),
            ], $criteria),
        ];
    }

    /** Méthodologiste : plan complet, empreinte avec incertitudes, critères rétrogradés. */
    private function methodologist(Assessment $a, array $criteria): array
    {
        return [
            'fingerprint' => $a->getFingerprint(),
            'plan' => $a->getPlan(),
            'routing_confidence' => $a->getRoutingConfidence(),
            'downgraded_criteria' => array_values(array_map(
                static fn (AssessmentCriterion $c): string => $c->getCriterionId(),
                array_filter($criteria, static fn (AssessmentCriterion $c): bool => $c->requiresHumanReview()),
            )),
            'criteria' => array_map(fn (AssessmentCriterion $c): array => [
                'criterion_id' => $c->getCriterionId(),
                'answer' => $c->getAnswer(),
                'evidence_type' => $c->getEvidenceType(),
                'confidence' => $c->getConfidence(),
            ], $criteria),
        ];
    }

    /** Relecteur : défauts de reporting / risques, informations manquantes. */
    private function reviewer(array $criteria, array $evidence): array
    {
        $problems = array_values(array_filter(
            $criteria,
            static fn (AssessmentCriterion $c): bool => \in_array($c->getAnswer(), ['no', 'partial', 'high', 'some_concerns', 'unclear', 'cant_tell'], true),
        ));

        return [
            'issues' => array_map(static fn (AssessmentCriterion $c): array => [
                'criterion_id' => $c->getCriterionId(),
                'question' => $c->getQuestion(),
                'answer' => $c->getAnswer(),
                'analysis' => $c->getAnalysis(),
            ], $problems),
            'issues_count' => \count($problems),
        ];
    }

    /** Éditeur : problèmes bloquants uniquement (risque élevé, revue humaine requise). */
    private function editor(Assessment $a, array $criteria): array
    {
        $blocking = array_values(array_filter(
            $criteria,
            static fn (AssessmentCriterion $c): bool => $c->requiresHumanReview() || 'high' === $c->getAnswer(),
        ));

        return [
            'blocking_issues' => array_map(static fn (AssessmentCriterion $c): array => [
                'criterion_id' => $c->getCriterionId(),
                'answer' => $c->getAnswer(),
            ], $blocking),
            'blocking_count' => \count($blocking),
            'recommendation' => [] === $blocking ? 'no_blocking_issue' : 'needs_attention',
        ];
    }

    /** Clinicien : validité interne, applicabilité, limites pratiques (sans jargon). */
    private function clinician(Assessment $a, array $criteria): array
    {
        $limitations = array_values(array_filter(
            $criteria,
            static fn (AssessmentCriterion $c): bool => \in_array($c->getDimension(), ['limitations', 'measurement_validity', 'sample_size', 'target_population'], true),
        ));

        return [
            'internal_validity' => $a->isHumanReview() ? 'à confirmer' : 'évaluée',
            'applicability_note' => $this->applicability($a),
            'key_limitations' => array_map(static fn (AssessmentCriterion $c): string => $c->getQuestion().' → '.$c->getAnswer(), $limitations),
        ];
    }

    /** Journaliste / vulgarisateur : ce que l'étude montre / ne montre pas, solidité. */
    private function journalist(Assessment $a, array $criteria): array
    {
        $total = \count($criteria);
        $solid = \count(array_filter($criteria, static fn (AssessmentCriterion $c): bool => \in_array($c->getAnswer(), ['yes', 'low'], true)));
        $solidity = $total > 0 ? ($solid / $total >= 0.7 ? 'solide' : ($solid / $total >= 0.4 ? 'modérée' : 'fragile')) : 'indéterminée';
        $observational = \in_array($a->getPrimaryDesign(), ['cross_sectional', 'cohort_prospective', 'cohort_retrospective', 'case_control', 'ecological'], true);

        return [
            'plain_summary' => \sprintf(
                "Type d'étude : %s. Solidité méthodologique apparente : %s.%s",
                $a->getFingerprint()['design_label'] ?? 'indéterminé',
                $solidity,
                $a->isHumanReview() ? ' Résultat à confirmer par un relecteur.' : '',
            ),
            'causality' => $observational ? 'association (pas de causalité démontrée)' : 'à interpréter selon le protocole',
            'solidity' => $solidity,
        ];
    }

    /** Administrateur : traçabilité technique (modèle, versions, couverture, seuils). */
    private function admin(Assessment $a, array $criteria): array
    {
        $downgraded = \count(array_filter($criteria, static fn (AssessmentCriterion $c): bool => $c->requiresHumanReview()));

        return [
            'model' => $a->getModel(),
            'route_version' => $a->getPlan()['route_version'] ?? null,
            'requested_by' => $a->getRequestedBy(),
            'criteria_total' => \count($criteria),
            'criteria_downgraded' => $downgraded,
            'created_at' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function applicability(Assessment $a): string
    {
        return match ($a->getPrimaryDesign()) {
            'randomized_controlled_trial' => 'Preuve interventionnelle : applicabilité selon la population incluse.',
            'cross_sectional' => 'Étude transversale : décrit une situation, ne démontre pas de causalité.',
            'systematic_review', 'meta_analysis' => "Synthèse de preuves : applicabilité selon l'hétérogénéité des études incluses.",
            default => 'Applicabilité à évaluer selon le contexte clinique.',
        };
    }
}
