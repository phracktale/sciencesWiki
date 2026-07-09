<?php

declare(strict_types=1);

namespace App\Analysis\Axis;

use App\Enum\AxisAnswer;
use App\Enum\AxisApplicability;

/**
 * Sortie LLM parsée d'une évaluation AXIS (cf. docs/spec-axis-articles.md §5),
 * avant garde-fou anti-hallucination et persistance ({@see AxisAppraiser}). Pur.
 */
final class ParsedAxisAppraisal
{
    /**
     * @param array<string,AxisAnswer> $answers        q1…q20 → réponse
     * @param array<string,array{
     *     verdict:?string,
     *     expected:?string,
     *     evidence_found:?string,
     *     analysis:?string,
     *     limitations:?string,
     *     evidence:list<array{source_type:?string,section:?string,quote:?string,evidence_type:?string}>,
     *     evidence_type:?string,
     *     overall_evidence_type:?string,
     *     confidence:?string,
     *     requires_visual_check:bool,
     *     reasoning:?string,
     *     quote:?string
     * }> $justifications  q → analyse structurée par item (avant garde-fou)
     */
    public function __construct(
        public readonly AxisApplicability $applicability,
        public readonly ?string $studyDesign,
        public readonly array $answers,
        public readonly array $justifications,
        public readonly ?string $summary,
    ) {
    }
}
