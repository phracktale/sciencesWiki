<?php

declare(strict_types=1);

namespace App\Search;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client Meilisearch (recherche plein-texte TOLÉRANT AUX FAUTES) du corpus.
 * Pilote l'index « publications » : configuration, indexation par lots, recherche.
 * La clé maître reste côté serveur ; le front passe par l'API.
 */
final class SearchEngine
{
    public const INDEX = 'publications';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'MEILI_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'MEILI_MASTER_KEY')]
        private readonly string $key,
    ) {
    }

    public function enabled(): bool
    {
        return '' !== trim($this->baseUrl);
    }

    /** Crée l'index + applique les réglages (idempotent). */
    public function configure(): void
    {
        $this->request('POST', '/indexes', ['uid' => self::INDEX, 'primaryKey' => 'id']);
        $this->request('PATCH', '/indexes/'.self::INDEX.'/settings', [
            'searchableAttributes' => ['title', 'abstract', 'lead_author'],
            'filterableAttributes' => ['year', 'type', 'oa_status', 'journal', 'retraction_status'],
            'sortableAttributes' => ['cited_by_count', 'fwci', 'year'],
            'displayedAttributes' => ['id', 'doi', 'title', 'journal', 'year', 'type', 'oa_status', 'cited_by_count', 'fwci', 'oa_url', 'lead_author'],
            'typoTolerance' => ['enabled' => true, 'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]],
            'pagination' => ['maxTotalHits' => 5000],
        ]);
    }

    /** @param list<array<string,mixed>> $docs */
    public function indexBatch(array $docs): void
    {
        if ([] === $docs) {
            return;
        }
        $this->request('POST', '/indexes/'.self::INDEX.'/documents', $docs);
    }

    /**
     * @param array<string,mixed> $opts filter, sort, limit, offset
     *
     * @return array<string,mixed> réponse Meilisearch (hits, estimatedTotalHits, …)
     */
    public function search(string $q, array $opts = []): array
    {
        $body = array_merge([
            'q' => $q,
            'limit' => 20,
            'showRankingScore' => true,
            'attributesToHighlight' => ['title'],
            'highlightPreTag' => '<mark>',
            'highlightPostTag' => '</mark>',
        ], $opts);

        return $this->request('POST', '/indexes/'.self::INDEX.'/search', $body) ?? ['hits' => []];
    }

    /** @return array<string,mixed>|null */
    public function stats(): ?array
    {
        return $this->request('GET', '/indexes/'.self::INDEX.'/stats');
    }

    /**
     * @param array<string,mixed>|list<mixed>|null $body
     *
     * @return array<string,mixed>|null
     */
    private function request(string $method, string $path, array|null $body = null): ?array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->key], 'timeout' => 30];
        if (null !== $body) {
            $options['json'] = $body;
        }
        try {
            $resp = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, $options);
            $status = $resp->getStatusCode();
            // 201/202 (tâches async Meili), 200 ; 409 = index déjà créé (OK).
            if (409 === $status) {
                return null;
            }

            return $resp->toArray(false);
        } catch (\Throwable) {
            return null;
        }
    }
}
