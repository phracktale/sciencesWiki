<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

/**
 * Embedder déterministe par *feature hashing* (sac de mots projeté puis
 * normalisé L2). Sans dépendance ni modèle : il porte un signal lexical
 * suffisant pour faire tourner et **vérifier** tout le pipeline (stockage
 * pgvector, kNN, placement) sans télécharger de modèle.
 *
 * ⚠️ Pas de sémantique fine : la qualité réelle exige le service `ml/`
 * (sentence-transformers) via {@see HttpEmbeddingClient}.
 */
final class HashingEmbeddingClient implements EmbeddingClient
{
    public function embed(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);

        foreach ($this->tokenize($text) as $token) {
            // Index et signe dérivés de hachages indépendants (feature hashing signé).
            $index = (int) (hexdec(substr(hash('sha256', $token), 0, 8)) % self::DIMENSIONS);
            $sign = (0 === hexdec(substr(hash('crc32b', $token), 0, 2)) % 2) ? 1.0 : -1.0;
            $vector[$index] += $sign;
        }

        return $this->normalize($vector);
    }

    public function dimensions(): int
    {
        return self::DIMENSIONS;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY);

        return false === $tokens ? [] : array_values(array_filter($tokens, static fn (string $t): bool => mb_strlen($t) > 1));
    }

    /**
     * @param list<float> $vector
     *
     * @return list<float>
     */
    private function normalize(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $component) {
            $norm += $component * $component;
        }
        $norm = sqrt($norm);

        if (0.0 === $norm) {
            // Texte vide : vecteur unitaire arbitraire mais déterministe.
            $vector[0] = 1.0;

            return $vector;
        }

        return array_map(static fn (float $c): float => $c / $norm, $vector);
    }
}
