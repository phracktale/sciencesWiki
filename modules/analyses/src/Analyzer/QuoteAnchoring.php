<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

/**
 * Vérification d'ancrage des citations (anti-hallucination) : une citation est ancrée
 * si une SÉQUENCE contiguë (≥ 6 mots) existe LITTÉRALEMENT dans le texte, tolérante aux
 * accents/ponctuation. Amélioration propre au module (le legacy faisait confiance au
 * type de preuve auto-déclaré par le LLM).
 */
trait QuoteAnchoring
{
    protected function quoteInText(string $quote, string $text): bool
    {
        $nt = $this->normalizeForMatch($text);
        $words = explode(' ', $this->normalizeForMatch($quote));
        $n = \count($words);
        if ($n < 5) {
            return false;
        }

        $window = 6;
        if ($n <= $window) {
            return str_contains($nt, implode(' ', $words));
        }
        for ($i = 0; $i + $window <= $n; ++$i) {
            if (str_contains($nt, implode(' ', \array_slice($words, $i, $window)))) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeForMatch(string $s): string
    {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($s);
    }
}
