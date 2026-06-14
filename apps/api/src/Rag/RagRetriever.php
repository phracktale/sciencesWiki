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
     * @return list<Publication>
     */
    public function retrieve(Question $question, int $k): array
    {
        $embedding = $question->getEmbedding()?->toArray()
            ?? $this->embeddingFactory->create()->embed($question->getText());

        return array_map(
            static fn (array $hit): Publication => $hit['publication'],
            $this->publications->nearestTo($embedding, $k),
        );
    }
}
