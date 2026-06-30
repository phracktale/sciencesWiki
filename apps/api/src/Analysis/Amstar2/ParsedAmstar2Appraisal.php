<?php

declare(strict_types=1);

namespace App\Analysis\Amstar2;

/**
 * Résultat structuré du parsing de la sortie LLM AMSTAR-2 (avant garde-fou/persistance).
 */
final class ParsedAmstar2Appraisal
{
    /**
     * @param 'applicable'|'not_applicable'|'uncertain' $applicability
     * @param array<string,string>                      $answers        clé item → yes|partial_yes|no
     * @param array<string,string>                      $justifications clé item → citation
     */
    public function __construct(
        public readonly string $applicability,
        public readonly ?string $studyDesign,
        public readonly array $answers,
        public readonly array $justifications,
        public readonly ?string $summary,
    ) {
    }
}
