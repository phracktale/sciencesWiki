<?php

declare(strict_types=1);

namespace App\Rag;

use App\Entity\Publication;
use App\Entity\Question;
use App\Harvester\Ai\EmbeddingClientFactory;
use App\Repository\PublicationRepository;

/**
 * Récupère les publications les plus pertinentes pour une question (similarité
 * d'embeddings pgvector). Étape « retrieval » du RAG (cf. docs/rag-server.md §3).
 *
 * NB : récupération au niveau publication (titre + résumé) pour cette première
 * version ; le découpage en passages (chunks) est une amélioration ultérieure.
 */
final class RagRetriever
{
    public function __construct(
        private readonly EmbeddingClientFactory $embeddingFactory,
        private readonly PublicationRepository $publications,
    ) {
    }

    /**
     * @param float|null $maxDistance distance cosinus maximale (0 = identique) ;
     *                                au-delà, la source est jugée hors-sujet et
     *                                écartée. null = pas de filtrage (rétro-compat).
     *
     * @return list<Publication>
     */
    public function retrieve(Question $question, int $k, ?float $maxDistance = null): array
    {
        $embedding = $question->getEmbedding()?->toArray()
            ?? $this->embeddingFactory->create()->embed($question->getText());

        $hits = $this->publications->nearestTo($embedding, $k);
        if (null !== $maxDistance) {
            $hits = array_values(array_filter($hits, static fn (array $h): bool => $h['distance'] <= $maxDistance));
        }

        return array_map(static fn (array $hit): Publication => $hit['publication'], $hits);
    }
}
