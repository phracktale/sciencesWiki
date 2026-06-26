<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism;

/**
 * Confirmation exacte (cf. docs/spec-plagiat.md §5.2) : Jaccard sur les ensembles de
 * shingles. Le MinHash n'a servi qu'au RAPPEL (LSH) ; ici on confirme précisément
 * pour éliminer la proximité purement thématique.
 */
final class OverlapScorer
{
    public function __construct(private readonly Shingler $shingler)
    {
    }

    /**
     * Ensemble de shingles d'un texte, sous forme de map (flip) pour des intersections
     * en O(n).
     *
     * @return array<string,bool>
     */
    public function shingleSet(string $text): array
    {
        return array_fill_keys($this->shingler->shingles($text), true);
    }

    /**
     * Jaccard exact entre deux ensembles de shingles (maps flip).
     *
     * @param array<string,bool> $a
     * @param array<string,bool> $b
     */
    public function jaccard(array $a, array $b): float
    {
        if ([] === $a || [] === $b) {
            return 0.0;
        }
        $inter = \count(array_intersect_key($a, $b));
        if (0 === $inter) {
            return 0.0;
        }
        $union = \count($a) + \count($b) - $inter;

        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * Part des shingles de A couverts par B (0..1) — base de l'overlapRatio publication.
     *
     * @param array<string,bool> $a
     * @param array<string,bool> $b
     */
    public function coverage(array $a, array $b): float
    {
        if ([] === $a) {
            return 0.0;
        }

        return \count(array_intersect_key($a, $b)) / \count($a);
    }
}
