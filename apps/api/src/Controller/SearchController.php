<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Publication;
use App\Entity\TreeNode;
use App\Harvester\Ai\EmbeddingClientFactory;
use App\Repository\PublicationRepository;
use App\Repository\TreeNodeRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recherche dans l'index moissonné : sémantique (embeddings pgvector) ou
 * plein-texte. Lecture publique (cf. spec §10 — front et apps consomment l'API).
 */
final class SearchController
{
    public function __construct(
        private readonly EmbeddingClientFactory $embeddingFactory,
        private readonly PublicationRepository $publications,
        private readonly TreeNodeRepository $nodes,
    ) {
    }

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $type = $request->query->get('type', 'publications');
        $mode = $request->query->get('mode', 'semantic');
        $limit = max(1, min(50, $request->query->getInt('limit', 10)));

        if ('' === $query) {
            return new JsonResponse(['error' => 'Le paramètre « q » est requis.'], 400);
        }

        $results = match (true) {
            'nodes' === $type => $this->searchNodes($query, $limit),
            'text' === $mode => $this->textPublications($query, $limit),
            default => $this->semanticPublications($query, $limit),
        };

        return new JsonResponse([
            'query' => $query,
            'type' => $type,
            'mode' => 'nodes' === $type ? 'semantic' : $mode,
            'count' => \count($results),
            'results' => $results,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function semanticPublications(string $query, int $limit): array
    {
        $embedding = $this->embeddingFactory->create()->embed($query);

        return array_map(
            fn (array $hit): array => ['score' => round(1.0 - $hit['distance'], 4)] + $this->publicationSummary($hit['publication']),
            $this->publications->nearestTo($embedding, $limit),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function textPublications(string $query, int $limit): array
    {
        return array_map(
            fn (Publication $p): array => ['score' => null] + $this->publicationSummary($p),
            $this->publications->textSearch($query, $limit),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchNodes(string $query, int $limit): array
    {
        $embedding = $this->embeddingFactory->create()->embed($query);

        return array_map(
            static fn (array $hit): array => [
                'score' => round(1.0 - $hit['distance'], 4),
                'slug' => $hit['node']->getSlug(),
                'label' => $hit['node']->getLabel(),
                'level' => $hit['node']->getLevel(),
                'domain' => $hit['node']->getDomain(),
            ],
            $this->nodes->nearestTo($embedding, $limit),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function publicationSummary(Publication $p): array
    {
        return [
            'doi' => $p->getDoi(),
            'title' => $p->getTitle(),
            'venue' => $p->getVenue(),
            'oaStatus' => $p->getOaStatus()->value,
            'oaUrl' => $p->getOaUrl(),
            'authors' => array_map(static fn (array $a): string => $a['name'], $p->getAuthors()),
        ];
    }
}
