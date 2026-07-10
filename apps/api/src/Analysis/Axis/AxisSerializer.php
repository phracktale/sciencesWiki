<?php

declare(strict_types=1);

namespace App\Analysis\Axis;

use App\Catalog\AxisChecklist;
use App\Entity\AxisAppraisal;
use App\Enum\AxisAnswer;

/**
 * Met en forme une évaluation AXIS pour l'API (front public et back-office). Joint
 * les libellés des 20 items du {@see AxisChecklist} aux réponses stockées, et
 * rappelle l'avertissement méthodologique (checklist, pas un score).
 */
final class AxisSerializer
{
    /** Avertissement affiché systématiquement (les auteurs déconseillent un score). */
    public const DISCLAIMER = 'AXIS est une grille de lecture critique des études transversales (Downes et al., BMJ Open 2016), pas une note : la bande de fiabilité est purement indicative. Évaluation automatique générée par IA.';

    /**
     * @return array<string,mixed>
     */
    public function serialize(AxisAppraisal $appraisal): array
    {
        $answers = $appraisal->getAnswers();
        $justifications = $appraisal->getJustifications();

        $items = [];
        foreach (AxisChecklist::ITEMS as $key => $meta) {
            if (!isset($answers[$key])) {
                continue;
            }
            $answer = AxisAnswer::tryFrom($answers[$key]) ?? AxisAnswer::Unclear;
            // Compat : ancien format (string = citation) ou nouveau ({verdict,evidence_type,…}).
            $detail = $justifications[$key] ?? null;
            $verdict = \is_array($detail) ? ($detail['verdict'] ?? null) : null;
            $reasoning = \is_array($detail) ? ($detail['reasoning'] ?? null) : null;
            $quote = \is_array($detail) ? ($detail['quote'] ?? null) : (\is_string($detail) ? $detail : null);
            $items[] = [
                'key' => $key,
                'section' => $meta['section'],
                'question' => $meta['text'],
                'answer' => $answer->value,
                // Libellé NUANCÉ du modèle (« Oui, avec réserve »…) sinon le libellé canonique.
                'answerLabel' => '' !== (string) $verdict ? $verdict : $answer->label(),
                'favorable' => AxisChecklist::isFavorable($key, $answer),
                'reverse' => \in_array($key, AxisChecklist::REVERSE, true),
                'reasoning' => $reasoning,
                'quote' => $quote,
                'anchored' => \is_array($detail) ? (bool) ($detail['anchored'] ?? false) : false,
                // Analyse structurée (vue « audit ») + traçabilité de la décision.
                'expected' => \is_array($detail) ? ($detail['expected'] ?? null) : null,
                'evidenceFound' => \is_array($detail) ? ($detail['evidence_found'] ?? null) : null,
                'analysis' => \is_array($detail) ? ($detail['analysis'] ?? $reasoning) : $reasoning,
                'limitations' => \is_array($detail) ? ($detail['limitations'] ?? null) : null,
                'evidence' => \is_array($detail) && \is_array($detail['evidence'] ?? null) ? array_values($detail['evidence']) : [],
                'evidenceType' => \is_array($detail) ? ($detail['evidence_type'] ?? null) : null,
                'overallEvidenceType' => \is_array($detail) ? ($detail['overall_evidence_type'] ?? null) : null,
                'confidence' => \is_array($detail) ? ($detail['confidence'] ?? null) : null,
                'requiresVisualCheck' => \is_array($detail) ? (bool) ($detail['requires_visual_check'] ?? false) : false,
                'downgraded' => \is_array($detail) ? (bool) ($detail['downgraded'] ?? false) : false,
            ];
        }

        return [
            'id' => $appraisal->getId(),
            'applicability' => $appraisal->getApplicability()->value,
            'applicabilityLabel' => $appraisal->getApplicability()->label(),
            'studyDesign' => $appraisal->getStudyDesign(),
            'reliabilityBand' => $appraisal->getReliabilityBand(),
            'favorableCount' => $appraisal->getFavorableCount(),
            'assessableCount' => $appraisal->getAssessableCount(),
            'sourceScope' => $appraisal->getSourceScope(),
            'sourceCoverage' => $this->sourceCoverage((string) $appraisal->getSourceScope()),
            'summary' => $appraisal->getSummary(),
            'status' => $appraisal->getStatus()->value,
            'model' => $appraisal->getAppraisalModel(),
            'generationMs' => $appraisal->getGenerationMs(),
            'tokens' => $appraisal->getTokens(),
            'createdAt' => $appraisal->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'items' => $items,
            // Disclaimer + modèle réellement utilisé (traçabilité, comparaison de modèles).
            'disclaimer' => self::DISCLAIMER.' Modèle : '.($appraisal->getAppraisalModel() ?: 'non précisé').'.',
        ];
    }

    /**
     * Couverture des sources RÉELLEMENT analysées. L'évaluation ne lit que le TEXTE extrait
     * (GROBID) : les tableaux/figures sous forme d'IMAGE ne sont pas vus (pas de pipeline
     * visuel). Exposé honnêtement pour interpréter avec prudence les items qui dépendent
     * d'un tableau/figure (données de base, résultats, cohérence interne).
     *
     * @return array<string,mixed>
     */
    private function sourceCoverage(string $scope): array
    {
        $hasFulltext = str_contains($scope, 'fulltext');

        return [
            'abstract' => true,
            'fulltext' => $hasFulltext,
            'tablesTextExtracted' => $hasFulltext, // GROBID capture une PARTIE du texte des tableaux
            'figuresExtracted' => false,
            'pageImagesAvailable' => false,
            'visualReviewPerformed' => false,
            'coverageWarning' => $hasFulltext
                ? "Les tableaux et figures sous forme d'image n'ont pas été analysés visuellement : les items qui en dépendent (données de base, résultats, cohérence interne) peuvent être incomplets."
                : 'Évaluation fondée sur le RÉSUMÉ seul : la plupart des items ne peuvent pas être vérifiés en profondeur.',
        ];
    }
}
