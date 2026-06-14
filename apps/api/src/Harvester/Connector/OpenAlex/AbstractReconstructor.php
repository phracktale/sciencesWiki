<?php

declare(strict_types=1);

namespace App\Harvester\Connector\OpenAlex;

/**
 * Reconstruit le texte d'un résumé à partir de l'index inversé d'OpenAlex.
 *
 * OpenAlex ne fournit pas le résumé en clair mais sous forme
 * `{"mot": [positions...]}`. On replace chaque mot à ses positions.
 */
final class AbstractReconstructor
{
    /**
     * @param array<string,list<int>>|null $invertedIndex
     */
    public static function reconstruct(?array $invertedIndex): ?string
    {
        if (null === $invertedIndex || [] === $invertedIndex) {
            return null;
        }

        $positions = [];
        foreach ($invertedIndex as $word => $indices) {
            foreach ($indices as $index) {
                $positions[$index] = $word;
            }
        }

        if ([] === $positions) {
            return null;
        }

        ksort($positions);

        $text = trim(implode(' ', $positions));

        return '' === $text ? null : $text;
    }
}
