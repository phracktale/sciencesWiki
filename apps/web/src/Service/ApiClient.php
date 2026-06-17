<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API SciencesWiki. Le front Twig ne touche jamais la base : il
 * consomme l'API (cf. spec §5). Base configurée par API_BASE_URL.
 */
final class ApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'API_BASE_URL')]
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Grands domaines (racines de l'arbre).
     *
     * @return list<array<string,mixed>>
     */
    public function domains(): array
    {
        return $this->get('/api/tree_nodes', ['level' => 0, 'order[label]' => 'asc']);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function node(string $slug): ?array
    {
        try {
            return $this->get('/api/tree_nodes/'.rawurlencode($slug));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Q/R publiques d'un nœud.
     *
     * @return list<array<string,mixed>>
     */
    public function answers(string $slug): array
    {
        return $this->get('/api/answers', ['treeNode.slug' => $slug]);
    }

    /**
     * Une réponse (Q/R) par son identifiant.
     *
     * @return array<string,mixed>|null
     */
    public function answer(int $id): ?array
    {
        try {
            return $this->get('/api/answers/'.$id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Dernières questions posées (accueil).
     *
     * @return list<array<string,mixed>>
     */
    public function latestQuestions(int $limit = 10): array
    {
        try {
            $data = $this->get('/api/questions/latest', ['limit' => $limit]);

            return $data['items'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Statistiques globales du corpus.
     *
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        try {
            return $this->get('/api/stats');
        } catch (\Throwable) {
            return [];
        }
    }

    /** Nombre de publications d'une branche (nœud + descendants). */
    public function nodeCorpus(string $slug): int
    {
        try {
            return (int) ($this->get('/api/tree_nodes/'.rawurlencode($slug).'/corpus')['publications'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->httpClient->request('GET', $this->baseUrl.$path, [
            'query' => $query,
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 10,
        ])->toArray();
    }
}
