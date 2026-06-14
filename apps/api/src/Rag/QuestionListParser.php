<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Extrait une liste de questions d'une réponse LLM, qu'elle soit en JSON
 * (tableau de chaînes) ou en texte (une question par ligne, avec puces/numéros).
 */
final class QuestionListParser
{
    /**
     * @return list<string>
     */
    public static function parse(string $content): array
    {
        $fromJson = self::fromJsonArray($content);
        if (null !== $fromJson) {
            return $fromJson;
        }

        return self::fromLines($content);
    }

    /**
     * @return list<string>|null
     */
    private static function fromJsonArray(string $content): ?array
    {
        $start = strpos($content, '[');
        $end = strrpos($content, ']');
        if (false === $start || false === $end || $end < $start) {
            return null;
        }

        try {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }

        $questions = [];
        foreach ($decoded as $item) {
            if (\is_string($item)) {
                $clean = self::clean($item);
                if ('' !== $clean) {
                    $questions[] = $clean;
                }
            }
        }

        return [] === $questions ? null : $questions;
    }

    /**
     * @return list<string>
     */
    private static function fromLines(string $content): array
    {
        $questions = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            // Retire puces et numérotation de tête.
            $clean = self::clean((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line));
            if (mb_strlen($clean) > 8) {
                $questions[] = $clean;
            }
        }

        return $questions;
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
