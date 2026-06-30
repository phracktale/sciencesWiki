<?php

declare(strict_types=1);

namespace App\Analysis\Rob2;

/**
 * Résultat structuré du parsing de la sortie LLM RoB 2 (avant garde-fou/persistance).
 */
final class ParsedRob2Appraisal
{
    /**
     * @param 'applicable'|'not_applicable'|'uncertain'                          $applicability
     * @param array<string,array{judgement:string,quote:?string,rationale:?string}> $domains
     */
    public function __construct(
        public readonly string $applicability,
        public readonly ?string $studyDesign,
        public readonly array $domains,
        public readonly ?string $summary,
    ) {
    }
}
