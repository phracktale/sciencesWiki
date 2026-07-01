<?php

declare(strict_types=1);

namespace App\Analysis\Mmat;

use App\Catalog\MmatChecklist;
use App\Entity\MmatAppraisal;

/**
 * Met en forme une évaluation MMAT pour l'API : questions de filtrage + cinq critères
 * de la catégorie retenue (avec leurs libellés), et repère de qualité indicatif.
 */
final class MmatSerializer
{
    public const DISCLAIMER = 'MMAT (Hong et al. 2018) évalue la qualité méthodologique d’une étude empirique selon sa catégorie (qualitative, quantitative, méthodes mixtes). Les auteurs déconseillent un score global : le « n/5 » n’est qu’un repère indicatif. Évaluation automatique générée par IA, à valider par un·e expert·e ; l’appréciation complète requiert le texte intégral.';

    private const APPLICABILITY = [
        'applicable' => 'Applicable (étude empirique)',
        'not_applicable' => 'Non applicable (étude non empirique ou revue systématique)',
        'uncertain' => 'Applicabilité incertaine',
    ];

    /** @return array<string,mixed> */
    public function serialize(MmatAppraisal $appraisal): array
    {
        $answers = $appraisal->getAnswers();
        $justifications = $appraisal->getJustifications();
        $category = $appraisal->getCategory();

        $screening = [];
        foreach (MmatChecklist::SCREENING as $key => $text) {
            if (!isset($answers[$key])) {
                continue;
            }
            $screening[] = $this->item($key, $text, $answers[$key], $justifications[$key] ?? null);
        }

        $criteria = [];
        foreach (MmatChecklist::criteriaFor($category) as $key => $text) {
            if (!isset($answers[$key])) {
                continue;
            }
            $criteria[] = $this->item($key, $text, $answers[$key], $justifications[$key] ?? null);
        }

        $applicability = $appraisal->getApplicability();
        $overall = $appraisal->getOverall();

        return [
            'id' => $appraisal->getId(),
            'tool' => 'mmat',
            'applicability' => $applicability,
            'applicabilityLabel' => self::APPLICABILITY[$applicability] ?? self::APPLICABILITY['uncertain'],
            'category' => $category,
            'categoryLabel' => MmatChecklist::categoryLabel($category),
            'studyDesign' => $appraisal->getStudyDesign(),
            'screeningPassed' => $appraisal->isScreeningPassed(),
            'metCount' => $appraisal->getMetCount(),
            'overall' => $overall,
            'overallLabel' => MmatChecklist::ratingLabel($overall),
            'sourceScope' => $appraisal->getSourceScope(),
            'summary' => $appraisal->getSummary(),
            'status' => $appraisal->getStatus()->value,
            'model' => $appraisal->getAppraisalModel(),
            'createdAt' => $appraisal->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'screening' => $screening,
            'items' => $criteria,
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /** @return array<string,mixed> */
    private function item(string $key, string $text, string $answer, ?string $quote): array
    {
        return [
            'key' => $key,
            'question' => $text,
            'answer' => $answer,
            'answerLabel' => MmatChecklist::answerLabel($answer),
            'favorable' => 'yes' === $answer,
            'flaw' => 'no' === $answer,
            'quote' => $quote,
        ];
    }
}
