<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism;

/**
 * Découpe un texte en n-grammes de mots (« shingles ») normalisés (cf. spec-plagiat.md
 * §2). La normalisation (minuscule, sans accents ni ponctuation, espaces compactés)
 * rend la comparaison robuste aux variations de surface. L'ensemble des shingles sert
 * au Jaccard exact (confirmation) et au MinHash (rappel LSH).
 */
final class Shingler
{
    /** Taille du n-gramme (mots). */
    public const K = 5;

    /**
     * Ensemble (uniques) des shingles d'un texte.
     *
     * @return list<string>
     */
    public function shingles(string $text, int $k = self::K): array
    {
        $words = $this->words($text);
        $n = \count($words);
        if ($n < $k) {
            // Trop court pour un n-gramme plein : un seul shingle = le fragment entier.
            return 0 === $n ? [] : [implode(' ', $words)];
        }

        $set = [];
        $last = $n - $k;
        for ($i = 0; $i <= $last; ++$i) {
            $set[implode(' ', \array_slice($words, $i, $k))] = true;
        }

        return array_keys($set);
    }

    /**
     * Mots normalisés : minuscule, accents translittérés (é→e), tout caractère non
     * alphanumérique traité comme séparateur.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (false !== $ascii && '' !== $ascii) {
            $text = $ascii;
        }
        $text = preg_replace('/[^a-z0-9]+/i', ' ', $text) ?? '';
        $words = preg_split('/\s+/', trim($text)) ?: [];

        return array_values(array_filter($words, static fn (string $w): bool => '' !== $w));
    }
}
