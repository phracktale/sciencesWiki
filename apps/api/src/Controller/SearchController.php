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

        // Filtre par type (cases « Articles » / « Preprint ») + option « privilégier les cités ».
        $typeMap = [
            'article' => ['article', 'journalArticle', 'conferencePaper', 'conference-paper'],
            'preprint' => ['preprint'],
        ];
        $types = [];
        foreach ($request->query->all('types') as $t) {
            foreach ($typeMap[$t] ?? [(string) $t] as $x) {
                $types[] = $x;
            }
        }
        $boostCited = $request->query->getBoolean('boost');

        $results = match (true) {
            'nodes' === $type => $this->searchNodes($query, $limit),
            'text' === $mode => $this->textPublications($query, $limit),
            default => $this->semanticPublications($query, $limit, $types, $boostCited),
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
    /**
     * @param list<string> $types restreint aux types (vide = tous)
     */
    private function semanticPublications(string $query, int $limit, array $types = [], bool $boostCited = false): array
    {
        $embedding = $this->embeddingFactory->create()->embed($query);
        // Pour privilégier les cités : on sur-échantillonne puis on re-classe.
        $fetch = $boostCited ? min($limit * 4, 80) : $limit;

        $rows = array_map(
            fn (array $hit): array => ['score' => round(1.0 - $hit['distance'], 4)] + $this->publicationSummary($hit['publication']),
            $this->publications->nearestTo($embedding, $fetch, $types),
        );

        if ($boostCited) {
            // Score combiné = pertinence + petit bonus logarithmique de notoriété
            // (les très cités remontent sans écraser la pertinence sémantique).
            $blend = static fn (array $r): float => (float) ($r['score'] ?? 0) + 0.08 * log10(1 + (int) ($r['citedByCount'] ?? 0));
            usort($rows, static fn (array $a, array $b): int => $blend($b) <=> $blend($a));
            $rows = \array_slice($rows, 0, $limit);
        }

        return $rows;
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
        $authors = array_map(static fn (array $a): string => $a['name'], $p->getAuthors());

        return [
            'doi' => $p->getDoi(),
            'title' => $p->getTitle(),
            'venue' => $p->getVenue(),
            'oaStatus' => $p->getOaStatus()->value,
            'oaUrl' => $p->getOaUrl(),
            'authors' => $authors,
            'leadAuthor' => $authors[0] ?? null,
            'citedByCount' => $p->getCitedByCount(),
            'fwci' => $p->getFwci(),
        ];
    }
}
