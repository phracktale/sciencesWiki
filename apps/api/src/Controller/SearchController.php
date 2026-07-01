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

        // Recherche par nœuds / plein-texte : liste simple, sans pagination.
        if ('nodes' === $type) {
            $results = $this->searchNodes($query, $limit);

            return new JsonResponse(['query' => $query, 'type' => $type, 'mode' => 'semantic', 'count' => \count($results), 'results' => $results]);
        }
        if ('text' === $mode) {
            $results = $this->textPublications($query, $limit);

            return new JsonResponse(['query' => $query, 'type' => $type, 'mode' => 'text', 'count' => \count($results), 'results' => $results]);
        }

        // Sémantique : filtre par type (« Articles » / « Preprints »), tri et pagination.
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
        $sort = (string) $request->query->get('sort', 'relevance');
        if (!\in_array($sort, ['relevance', 'cited', 'recent'], true)) {
            $sort = $request->query->getBoolean('boost') ? 'cited' : 'relevance';
        }
        $page = max(1, $request->query->getInt('page', 1));

        return new JsonResponse(
            ['query' => $query, 'type' => 'publications', 'mode' => 'semantic']
            + $this->semanticPublications($query, $page, $types, $sort),
        );
    }

    /**
     * Recherche sémantique paginée. On récupère un VIVIER des plus pertinents
     * (kNN, plafonné), on le trie selon $sort, puis on découpe la page demandée.
     *
     * @param list<string> $types restreint aux types (vide = tous)
     *
     * @return array{results:list<array<string,mixed>>,count:int,page:int,perPage:int,total:int,hasMore:bool}
     */
    private function semanticPublications(string $query, int $page, array $types = [], string $sort = 'relevance'): array
    {
        $perPage = 20;
        $poolMax = 120; // profondeur kNN utile (au-delà, la similarité décroche)

        $embedding = $this->embeddingFactory->create()->embed($query);
        $rows = array_map(
            fn (array $hit): array => ['score' => round(1.0 - $hit['distance'], 4)] + $this->publicationSummary($hit['publication']),
            $this->publications->nearestTo($embedding, $poolMax, $types),
        );

        if ('cited' === $sort) {
            // Score combiné = pertinence + petit bonus logarithmique de notoriété
            // (les très cités remontent sans écraser la pertinence sémantique).
            $blend = static fn (array $r): float => (float) ($r['score'] ?? 0) + 0.08 * log10(1 + (int) ($r['citedByCount'] ?? 0));
            usort($rows, static fn (array $a, array $b): int => $blend($b) <=> $blend($a));
        } elseif ('recent' === $sort) {
            // Date la plus proche (récente) d'abord ; sans date → en fin ; égalité → pertinence.
            usort($rows, static function (array $a, array $b): int {
                $da = (string) ($a['date'] ?? '');
                $db = (string) ($b['date'] ?? '');

                return $da === $db
                    ? (float) ($b['score'] ?? 0) <=> (float) ($a['score'] ?? 0)
                    : strcmp($db, $da);
            });
        }
        // 'relevance' : on conserve l'ordre du kNN.

        $total = \count($rows);
        $offset = ($page - 1) * $perPage;
        $pageRows = \array_slice($rows, $offset, $perPage);

        return [
            'results' => $pageRows,
            'count' => \count($pageRows),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'hasMore' => ($offset + $perPage) < $total,
        ];
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

        $date = $p->getPublicationDate();

        return [
            'id' => $p->getId(),
            'doi' => $p->getDoi(),
            'title' => $p->getTitle(),
            'venue' => $p->getVenue(),
            'oaStatus' => $p->getOaStatus()->value,
            'oaUrl' => $p->getOaUrl(),
            'authors' => $authors,
            'leadAuthor' => $authors[0] ?? null,
            'citedByCount' => $p->getCitedByCount(),
            'fwci' => $p->getFwci(),
            'date' => $date?->format('Y-m-d'),
            'year' => $date?->format('Y'),
        ];
    }
}
