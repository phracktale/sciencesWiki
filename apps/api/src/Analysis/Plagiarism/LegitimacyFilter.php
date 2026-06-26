<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism;

/**
 * Écarte du scoring les fragments dont le recouvrement n'est PAS un signal de plagiat
 * (cf. docs/spec-plagiat.md §5.2, §9). Lot 1 : sections de références/bibliographie et
 * fragments trop courts. La liste de boilerplate + fréquence de shingles viendra au lot 4.
 */
final class LegitimacyFilter
{
    /** En-deçà, un fragment est trop court pour un signal fiable (titre, légende). */
    private const MIN_CHARS = 120;

    public function shouldSkip(string $text): bool
    {
        $t = trim($text);

        return mb_strlen($t) < self::MIN_CHARS || $this->looksLikeReferences($t);
    }

    /**
     * Heuristique « section de références » : début explicite, ou forte densité de
     * marqueurs bibliographiques (……[12], « et al. », DOIs, années).
     */
    private function looksLikeReferences(string $text): bool
    {
        $head = mb_strtolower(mb_substr($text, 0, 200));
        if (1 === preg_match('/^\s*(references|bibliography|r[ée]f[ée]rences|bibliographie|works cited)\b/u', $head)) {
            return true;
        }
        $markers = preg_match_all('/\[\d+\]|\bet al\.|\bdoi:|\b(19|20)\d{2}[a-z]?\b/iu', $text) ?: 0;
        $perKiloChar = $markers / max(1.0, mb_strlen($text) / 1000);

        return $perKiloChar > 12.0;
    }
}
