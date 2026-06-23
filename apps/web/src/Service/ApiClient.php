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
    /** @return array{items: list<array<string,mixed>>, page:int, hasMore:bool} */
    public function latestQuestionsPage(int $perPage, int $page): array
    {
        try {
            $data = $this->get('/api/questions/latest', ['limit' => $perPage, 'page' => $page]);

            return ['items' => $data['items'] ?? [], 'page' => $data['page'] ?? $page, 'hasMore' => (bool) ($data['hasMore'] ?? false)];
        } catch (\Throwable) {
            return ['items' => [], 'page' => $page, 'hasMore' => false];
        }
    }

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

    /** Thème public du site ('legacy' | 'crt'), repli 'legacy' si l'API est muette. */
    public function publicTheme(): string
    {
        try {
            $v = (string) ($this->get('/api/public-settings')['theme'] ?? 'legacy');

            return \in_array($v, ['legacy', 'crt'], true) ? $v : 'legacy';
        } catch (\Throwable) {
            return 'legacy';
        }
    }

    /**
     * Controverses d'un nœud + état d'analyse (cf. spec controverses §8.1).
     * Le déclenchement (POST .../analyze) et le polling sont faits côté navigateur
     * (même origine /api), comme « poser une question ».
     *
     * @return array{node:array<string,mixed>,controversies:list<array<string,mixed>>}
     */
    public function controversies(string $slug): array
    {
        try {
            $data = $this->get('/api/tree_nodes/'.rawurlencode($slug).'/controversies');

            return ['node' => $data['node'] ?? [], 'controversies' => $data['controversies'] ?? []];
        } catch (\Throwable) {
            return ['node' => [], 'controversies' => []];
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
     * Stats par sous-rubrique directe : { slug: {publications, questions} }.
     *
     * @return array<string,array{publications:int,questions:int}>
     */
    public function nodeChildrenStats(string $slug): array
    {
        try {
            return $this->get('/api/tree_nodes/'.rawurlencode($slug).'/children-stats')['children'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Agrégats de votes (+ vote courant) pour un lot de réponses. Transmet le JWT
     * de session (poids/identité du votant) et l'IP réelle du client (anonymes).
     *
     * @param list<int> $ids
     *
     * @return array{tallies:array<string,mixed>,mine:array<string,mixed>}
     */
    public function answerVotes(array $ids, ?string $jwt, ?string $ip): array
    {
        if ([] === $ids) {
            return ['tallies' => [], 'mine' => []];
        }
        try {
            $data = $this->httpClient->request('GET', $this->baseUrl.'/api/answer-votes', [
                'query' => ['ids' => implode(',', $ids)],
                'headers' => $this->voterHeaders($jwt, $ip),
                'timeout' => 8,
            ])->toArray();

            return ['tallies' => $data['tallies'] ?? [], 'mine' => $data['mine'] ?? []];
        } catch (\Throwable) {
            return ['tallies' => [], 'mine' => []];
        }
    }

    /**
     * Enregistre un vote OK/Pas OK (bascule si re-cliqué). Retourne les agrégats.
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    public function voteAnswer(int $id, string $value, ?string $jwt, ?string $ip): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->baseUrl.'/api/answers/'.$id.'/vote', [
                'json' => ['value' => $value],
                'headers' => $this->voterHeaders($jwt, $ip),
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();

            return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $response->toArray(false)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * @return array<string,string>
     */
    private function voterHeaders(?string $jwt, ?string $ip): array
    {
        $headers = ['Accept' => 'application/json'];
        if (null !== $jwt && '' !== $jwt) {
            $headers['Authorization'] = 'Bearer '.$jwt;
        }
        if (null !== $ip && '' !== $ip) {
            $headers['X-Voter-Ip'] = $ip;
        }

        return $headers;
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
