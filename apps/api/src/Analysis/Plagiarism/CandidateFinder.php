<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism;

use App\Repository\ChunkFingerprintRepository;

/**
 * Étage 1 — RAPPEL (cf. docs/spec-plagiat.md §5). Réunit les chunks candidats au
 * niveau publication-cible. Lot 1 : voie LSH (verbatim). L'étage sémantique
 * (kNN pgvector, voie paraphrase) sera ajouté au lot 2 via une seconde source ici.
 */
final class CandidateFinder
{
    public function __construct(private readonly ChunkFingerprintRepository $fingerprints)
    {
    }

    /**
     * Couples de chunks candidats groupés par publication-cible (≠ source).
     *
     * @return array<int, list<array{srcChunkId:int, tgtChunkId:int}>> tgtPubId => paires
     */
    public function byTargetPublication(int $sourcePublicationId): array
    {
        $grouped = [];
        foreach ($this->fingerprints->candidateChunkPairs($sourcePublicationId) as $pair) {
            $grouped[$pair['tgtPubId']][] = [
                'srcChunkId' => $pair['srcChunkId'],
                'tgtChunkId' => $pair['tgtChunkId'],
            ];
        }

        return $grouped;
    }
}
