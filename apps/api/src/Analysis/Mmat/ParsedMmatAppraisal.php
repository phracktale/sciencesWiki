<?php

declare(strict_types=1);

namespace App\Analysis\Mmat;

/**
 * Résultat structuré du parsing de la sortie LLM MMAT (avant garde-fou/persistance).
 */
final class ParsedMmatAppraisal
{
    /**
     * @param 'applicable'|'not_applicable'|'uncertain' $applicability
     * @param array<string,string>                      $answers        clé item (s1/s2/c1…c5) → yes|no|cant_tell
     * @param array<string,string>                      $justifications clé item → citation
     */
    public function __construct(
        public readonly string $applicability,
        public readonly ?string $category,
        public readonly ?string $studyDesign,
        public readonly array $answers,
        public readonly array $justifications,
        public readonly ?string $summary,
    ) {
    }
}
