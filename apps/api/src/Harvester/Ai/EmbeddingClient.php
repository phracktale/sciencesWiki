<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

/**
 * Produit l'embedding (vecteur dense) d'un texte (cf. Phase 1 §8 / RAG).
 *
 * Abstraction : la production utilise un service auto-hébergé (sentence-
 * transformers via HTTP) ; un embedder déterministe local permet de faire
 * tourner et tester le pipeline sans modèle lourd.
 */
interface EmbeddingClient
{
    /** Dimension fixe des vecteurs (alignée sur la colonne pgvector). */
    public const DIMENSIONS = 384;

    /**
     * @return list<float> vecteur de dimension self::DIMENSIONS, normalisé L2
     */
    public function embed(string $text): array;

    public function dimensions(): int;
}
