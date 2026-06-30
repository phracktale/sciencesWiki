<?php

declare(strict_types=1);

namespace App\Analysis\Amstar2;

use App\Catalog\Amstar2Checklist;
use App\Entity\Amstar2Appraisal;

/**
 * Met en forme une évaluation AMSTAR-2 pour l'API : joint les libellés des 16 items
 * (en marquant les domaines critiques) et le niveau de confiance global.
 */
final class Amstar2Serializer
{
    public const DISCLAIMER = 'AMSTAR-2 (Shea et al., BMJ 2017) évalue la CONFIANCE dans une revue systématique selon sa rigueur méthodologique — ce n’est pas une note de la fiabilité des conclusions. Évaluation automatique générée par IA, à valider par un·e expert·e ; l’appréciation complète requiert le texte intégral.';

    private const APPLICABILITY = [
        'applicable' => 'Applicable (revue systématique)',
        'not_applicable' => 'Non applicable (autre type d’étude)',
        'uncertain' => 'Applicabilité incertaine',
    ];

    /** @return array<string,mixed> */
    public function serialize(Amstar2Appraisal $appraisal): array
    {
        $answers = $appraisal->getAnswers();
        $justifications = $appraisal->getJustifications();

        $items = [];
        foreach (Amstar2Checklist::ITEMS as $key => $text) {
            if (!isset($answers[$key])) {
                continue;
            }
            $answer = $answers[$key];
            $items[] = [
                'key' => $key,
                'question' => $text,
                'critical' => Amstar2Checklist::isCritical($key),
                'answer' => $answer,
                'answerLabel' => Amstar2Checklist::answerLabel($answer),
                'favorable' => 'yes' === $answer,
                'flaw' => 'no' === $answer,
                'quote' => $justifications[$key] ?? null,
            ];
        }

        $applicability = $appraisal->getApplicability();
        $overall = $appraisal->getOverall();

        return [
            'id' => $appraisal->getId(),
            'tool' => 'amstar2',
            'applicability' => $applicability,
            'applicabilityLabel' => self::APPLICABILITY[$applicability] ?? self::APPLICABILITY['uncertain'],
            'studyDesign' => $appraisal->getStudyDesign(),
            'overall' => $overall,
            'overallLabel' => Amstar2Checklist::ratingLabel($overall),
            'criticalFlaws' => $appraisal->getCriticalFlaws(),
            'weaknesses' => $appraisal->getWeaknesses(),
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
