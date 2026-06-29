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
     * @param array<string,AxisAnswer> $answers       q1…q20 → réponse
     * @param array<string,string>     $justifications q → citation verbatim (le cas échéant)
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
