<?php

declare(strict_types=1);

namespace App\Controller;

use App\Search\SearchEngine;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recherche plein-texte TOLÉRANT AUX FAUTES (Meilisearch) sur les papiers
 * primaires. Complémentaire de /api/search (sémantique pgvector).
 */
final class SearchKeywordController
{
    public function __construct(private readonly SearchEngine $engine)
    {
    }

    #[Route('/api/search/keyword', name: 'api_search_keyword', methods: ['GET'])]
    public function keyword(Request $request): JsonResponse
    {
        if (!$this->engine->enabled()) {
            return new JsonResponse(['error' => 'Recherche indisponible.', 'items' => []], 503);
        }

        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = 20;

        // Filtres (Meilisearch : tableau = ET logique).
        $filters = [];
        if (!$request->query->getBoolean('with_retracted')) {
            $filters[] = "retraction_status = 'none'";
        }
        if ('' !== ($year = trim((string) $request->query->get('year', '')))) {
            $filters[] = 'year = '.(int) $year;
        }
        if ('' !== ($type = trim((string) $request->query->get('type', '')))) {
            $filters[] = "type = '".str_replace("'", '', $type)."'";
        }
        if ($request->query->getBoolean('oa')) {
            $filters[] = "oa_status NOT IN ['closed', 'unknown']";
        }

        $sort = match ((string) $request->query->get('sort', '')) {
            'cited' => ['cited_by_count:desc'],
            'recent' => ['year:desc'],
            'fwci' => ['fwci:desc'],
            default => [],
        };

        $opts = [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'attributesToHighlight' => ['title'],
            'highlightPreTag' => '<mark>',
            'highlightPostTag' => '</mark>',
        ];
        if ([] !== $filters) {
            $opts['filter'] = $filters;
        }
        if ([] !== $sort) {
            $opts['sort'] = $sort;
        }

        $res = $this->engine->search($q, $opts);

        $items = array_map(static function (array $h): array {
            $oa = (string) ($h['oa_status'] ?? '');

            return [
                'id' => (int) ($h['id'] ?? 0),
                'title' => $h['title'] ?? '',
                'titleHighlighted' => $h['_formatted']['title'] ?? ($h['title'] ?? ''),
                'doi' => $h['doi'] ?? null,
                'journal' => $h['journal'] ?? null,
                'year' => $h['year'] ?? null,
                'type' => $h['type'] ?? null,
                'openAccess' => '' !== $oa && !\in_array($oa, ['closed', 'unknown'], true),
                'oaUrl' => $h['oa_url'] ?? null,
                'citedByCount' => $h['cited_by_count'] ?? 0,
                'fwci' => $h['fwci'] ?? null,
                'url' => ($h['oa_url'] ?? null) ?: (($h['doi'] ?? null) ? 'https://doi.org/'.$h['doi'] : null),
            ];
        }, $res['hits'] ?? []);

        return new JsonResponse([
            'items' => $items,
            'total' => $res['estimatedTotalHits'] ?? \count($items),
            'page' => $page,
            'query' => $q,
        ]);
    }
}
