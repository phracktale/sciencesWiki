<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * Bilan d'un run d'analyse (cf. docs/spec-controverses-lacunes.md §7bis).
 */
final class AnalysisResult
{
    public function __construct(
        public readonly int $publications,
        public readonly int $claims,
        public readonly int $controversies,
        public readonly int $gaps = 0,
        public readonly int $axisAppraisals = 0,
    ) {
    }
}
