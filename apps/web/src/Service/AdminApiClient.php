<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client des endpoints d'administration de l'API (ROLE_ADMIN). L'admin se
 * connecte via /api/login_check ; le JWT obtenu est conservé en session et
 * envoyé en Bearer sur les actions d'administration.
 */
final class AdminApiClient
{
    private const SESSION_KEY = 'admin_jwt';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RequestStack $requestStack,
        #[Autowire(env: 'API_BASE_URL')]
        private readonly string $baseUrl,
    ) {
    }

    /** Authentifie l'admin et stocke le JWT en session. Retourne true si OK. */
    public function login(string $email, string $password): bool
    {
        try {
            $data = $this->httpClient->request('POST', $this->baseUrl.'/api/login_check', [
                'json' => ['email' => $email, 'password' => $password],
                'timeout' => 10,
            ])->toArray(false);
        } catch (\Throwable) {
            return false;
        }

        $token = $data['token'] ?? null;
        if (!\is_string($token) || '' === $token) {
            return false;
        }
        $this->session()->set(self::SESSION_KEY, $token);

        return true;
    }

    public function logout(): void
    {
        $this->session()->remove(self::SESSION_KEY);
    }

    public function isLogged(): bool
    {
        return \is_string($this->session()->get(self::SESSION_KEY));
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function renameNode(int $id, string $label, ?string $description, ?string $imageUrl = null): array
    {
        return $this->send('PATCH', '/api/admin/nodes/'.$id, ['label' => $label, 'description' => $description, 'imageUrl' => $imageUrl]);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function setNodeImage(int $id, ?string $imageUrl): array
    {
        return $this->send('PATCH', '/api/admin/nodes/'.$id, ['imageUrl' => $imageUrl]);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function createNode(string $label, ?int $parentId, ?string $description): array
    {
        return $this->send('POST', '/api/admin/nodes', ['label' => $label, 'parentId' => $parentId, 'description' => $description]);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function moveNode(int $id, int $parentId): array
    {
        return $this->send('POST', '/api/admin/nodes/'.$id.'/move', ['parentId' => $parentId]);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function moveQuestion(int $id, int $nodeId): array
    {
        return $this->send('POST', '/api/admin/questions/'.$id.'/move', ['nodeId' => $nodeId]);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function deleteQuestion(int $id): array
    {
        return $this->send('DELETE', '/api/admin/questions/'.$id, null);
    }

    /** @return array<string,mixed> réglages courants */
    public function getSettings(): array
    {
        $res = $this->send('GET', '/api/admin/settings', null);

        return $res['ok'] ? $res['data'] : [];
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function saveSettings(array $values): array
    {
        return $this->send('PUT', '/api/admin/settings', $values);
    }

    /** @return array<string,mixed> données du dashboard admin */
    public function adminStats(): array
    {
        $res = $this->send('GET', '/api/admin/dashboard', null);

        return $res['ok'] ? $res['data'] : [];
    }

    /** @return array<string,mixed> modèles disponibles sur le serveur d'inférence */
    public function models(): array
    {
        $res = $this->send('GET', '/api/admin/models', null);

        return $res['ok'] ? $res['data'] : ['models' => [], 'default' => '', 'error' => 'Indisponible.'];
    }

    /** @return array<string,mixed> journal d'audit paginé */
    public function activity(string $category, int $page): array
    {
        $res = $this->send('GET', '/api/admin/activity?'.http_build_query(['category' => $category, 'page' => $page]), null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'category' => $category, 'categories' => []];
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} génère un lien de dépôt auteur */
    public function createContributionToken(int $id): array
    {
        return $this->send('POST', '/api/admin/publications/'.$id.'/contribution-token', []);
    }

    /** @return array<string,mixed>|null fiche détaillée d'un article */
    public function publication(int $id): ?array
    {
        $res = $this->send('GET', '/api/admin/publications/'.$id, null);

        return $res['ok'] ? $res['data'] : null;
    }

    /** @return array<string,mixed> liste paginée d'auteurs */
    public function authorsList(string $q, int $page, string $sort = '', string $dir = ''): array
    {
        $res = $this->send('GET', '/api/admin/authors?'.http_build_query(array_filter(['q' => $q, 'page' => $page, 'sort' => $sort, 'dir' => $dir])), null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'query' => $q];
    }

    /**
     * @param array<string,string|int> $filters revue/indexation/domaine/pdf/accès/tri
     *
     * @return array<string,mixed> liste paginée d'articles
     */
    public function articles(string $q, int $page, array $filters = []): array
    {
        $query = array_merge(['q' => $q, 'page' => $page], array_filter($filters, static fn ($v) => '' !== $v && null !== $v));
        $res = $this->send('GET', '/api/admin/publications?'.http_build_query($query), null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'query' => $q];
    }

    /** @return list<array<string,mixed>> revues correspondant à la recherche (autocomplete) */
    public function journalsSearch(string $q): array
    {
        $res = $this->send('GET', '/api/admin/journals?'.http_build_query(['q' => $q]), null);

        return $res['ok'] ? ($res['data']['items'] ?? []) : [];
    }

    /** @return array<string,mixed> liste des utilisateurs + rôles disponibles */
    public function users(): array
    {
        $res = $this->send('GET', '/api/admin/users', null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'availableRoles' => []];
    }

    /**
     * @param list<string> $roles
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    public function createUser(string $email, string $name, array $roles): array
    {
        return $this->send('POST', '/api/admin/users', ['email' => $email, 'name' => $name, 'roles' => $roles]);
    }

    /**
     * @param list<string> $roles
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    public function updateUserRoles(int $id, array $roles): array
    {
        return $this->send('PATCH', '/api/admin/users/'.$id, ['roles' => $roles]);
    }

    /** @return list<array<string,mixed>> concepts correspondants (recherche locale) */
    public function openalexSearch(string $q): array
    {
        $res = $this->send('GET', '/api/admin/openalex/search?'.http_build_query(['q' => $q]), null);

        return $res['ok'] ? ($res['data']['items'] ?? []) : [];
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function graftChildren(int $nodeId): array
    {
        return $this->send('POST', '/api/admin/nodes/'.$nodeId.'/graft-children', []);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function harvestNode(int $nodeId): array
    {
        return $this->send('POST', '/api/admin/nodes/'.$nodeId.'/harvest', []);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function cancelHarvest(int $nodeId): array
    {
        return $this->send('POST', '/api/admin/nodes/'.$nodeId.'/harvest/cancel', []);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function deleteHarvestLine(int $nodeId): array
    {
        return $this->send('POST', '/api/admin/nodes/'.$nodeId.'/harvest/delete', []);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function cleanupHarvest(string $mode): array
    {
        return $this->send('POST', '/api/admin/harvest/cleanup', ['mode' => $mode]);
    }

    /** @return array<string,mixed> état des moissons (workers, progression, quota OpenAlex) */
    public function harvestStatus(): array
    {
        $res = $this->send('GET', '/api/admin/harvest/status', null);

        return $res['ok'] ? $res['data'] : ['jobs' => [], 'queued' => 0, 'running' => [], 'openalex' => ['used' => 0, 'cap' => 100000, 'exhausted' => false]];
    }

    /**
     * @param array<string,mixed>|null $body
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    private function send(string $method, string $path, ?array $body): array
    {
        $token = $this->session()->get(self::SESSION_KEY);
        if (!\is_string($token)) {
            return ['ok' => false, 'status' => 401, 'data' => ['error' => 'Non authentifié.']];
        }

        $options = ['headers' => ['Authorization' => 'Bearer '.$token], 'timeout' => 20];
        if (null !== $body) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $this->baseUrl.$path, $options);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => ['error' => $e->getMessage()]];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data];
    }

    private function session(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        return $this->requestStack->getSession();
    }
}
