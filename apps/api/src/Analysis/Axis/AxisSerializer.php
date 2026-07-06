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
            // Compat : ancien format (string = citation) ou nouveau ({verdict,reasoning,quote,anchored}).
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
            'summary' => $appraisal->getSummary(),
            'status' => $appraisal->getStatus()->value,
            'model' => $appraisal->getAppraisalModel(),
            'createdAt' => $appraisal->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'items' => $items,
            'disclaimer' => self::DISCLAIMER,
        ];
    }
}
