<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Vérification de fidélité par HHEM (garde-fou rigoureux, cf. RAG Triad) : découpe le
 * texte en phrases déclaratives, score l'entailment de chacune contre les passages
 * sources, et insère « [réf. nécessaire] » après les phrases NON soutenues.
 *
 * APPEND-ONLY : on n'ajoute que des marqueurs, on ne réécrit/supprime jamais de texte
 * → contrairement à un LLM-juge, impossible de corrompre le contenu.
 */
final class HhemVerifier
{
    /** En-deçà de ce score d'entailment, la phrase est jugée non soutenue. */
    private const THRESHOLD = 0.5;

    /** Premise borné (le cross-encoder HHEM tronque ~512 tokens). */
    private const PREMISE_CHARS = 2000;

    public function __construct(private readonly HhemClient $hhem)
    {
    }

    public function isEnabled(): bool
    {
        return $this->hhem->isEnabled();
    }

    /**
     * @param string $premise concaténation des passages/résumés sources (l'évidence)
     */
    public function annotate(string $text, string $premise): string
    {
        $premise = mb_substr(trim((string) preg_replace('/\s+/', ' ', $premise)), 0, self::PREMISE_CHARS);
        if (!$this->hhem->isEnabled() || '' === $premise || '' === trim($text)) {
            return $text;
        }

        // Phrases déclaratives candidates (≥40 signes, se terminant par . ! ? ; hors
        // titres/listes/citations). On dédoublonne pour scorer chaque phrase une fois.
        preg_match_all('/(?:^|[\n.!?])\s*([^\n.!?]{40,}?[.!?])/u', $text, $matches);
        $sentences = [];
        foreach (array_unique(array_map('trim', $matches[1] ?? [])) as $s) {
            if ('' !== $s && !preg_match('/^[#\-*>|]/u', $s) && !str_contains($s, FaithfulnessChecker::MARKER)) {
                $sentences[] = $s;
            }
        }
        $sentences = array_values($sentences);
        if ([] === $sentences) {
            return $text;
        }

        $scores = $this->hhem->scoreBatch(array_map(static fn (string $s): array => [$premise, $s], $sentences));
        if (\count($scores) !== \count($sentences)) {
            return $text; // service indisponible / réponse incohérente → ne pas dégrader
        }

        foreach ($sentences as $i => $sentence) {
            if ($scores[$i] < self::THRESHOLD) {
                $text = $this->insertAfter($text, $sentence, ' '.FaithfulnessChecker::MARKER);
            }
        }

        return $text;
    }

    /** Insère $insert juste après la 1re occurrence de $needle (sans rien retirer). */
    private function insertAfter(string $haystack, string $needle, string $insert): string
    {
        $pos = mb_strpos($haystack, $needle);
        if (false === $pos) {
            return $haystack;
        }
        $end = $pos + mb_strlen($needle);

        return mb_substr($haystack, 0, $end).$insert.mb_substr($haystack, $end);
    }
}
