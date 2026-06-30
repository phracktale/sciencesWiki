<?php

declare(strict_types=1);

namespace App\Analysis\Rob2;

use App\Catalog\Rob2Checklist;
use App\Entity\Rob2Appraisal;

/**
 * Met en forme une évaluation RoB 2 pour l'API : joint les libellés des 5 domaines
 * et des jugements, et rappelle l'avertissement (risque de biais, pas une note).
 */
final class Rob2Serializer
{
    public const DISCLAIMER = 'RoB 2 (Sterne et al., BMJ 2019) évalue le RISQUE DE BIAIS d’un essai randomisé par domaine — ce n’est pas une note de qualité ni un verdict. Évaluation automatique générée par IA, à valider par un·e expert·e.';

    private const APPLICABILITY = [
        'applicable' => 'Applicable (essai randomisé)',
        'not_applicable' => 'Non applicable (autre type d’étude)',
        'uncertain' => 'Applicabilité incertaine',
    ];

    /** @return array<string,mixed> */
    public function serialize(Rob2Appraisal $appraisal): array
    {
        $stored = $appraisal->getDomains();
        $domains = [];
        foreach (Rob2Checklist::DOMAINS as $key => $meta) {
            if (!isset($stored[$key])) {
                continue;
            }
            $judgement = $stored[$key]['judgement'] ?? 'some_concerns';
            $domains[] = [
                'key' => $key,
                'title' => $meta['title'],
                'judgement' => $judgement,
                'judgementLabel' => Rob2Checklist::judgementLabel($judgement),
                'rationale' => $stored[$key]['rationale'] ?? null,
                'quote' => $stored[$key]['quote'] ?? null,
            ];
        }

        $applicability = $appraisal->getApplicability();
        $overall = $appraisal->getOverall();

        return [
            'id' => $appraisal->getId(),
            'tool' => 'rob2',
            'applicability' => $applicability,
            'applicabilityLabel' => self::APPLICABILITY[$applicability] ?? self::APPLICABILITY['uncertain'],
            'studyDesign' => $appraisal->getStudyDesign(),
            'overall' => $overall,
            'overallLabel' => Rob2Checklist::judgementLabel($overall),
            'sourceScope' => $appraisal->getSourceScope(),
            'summary' => $appraisal->getSummary(),
            'status' => $appraisal->getStatus()->value,
            'model' => $appraisal->getAppraisalModel(),
            'createdAt' => $appraisal->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'domains' => $domains,
            'disclaimer' => self::DISCLAIMER,
        ];
    }
}
