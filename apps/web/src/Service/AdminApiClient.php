<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client des endpoints d'administration de l'API (ROLE_ADMIN). L'admin se
 * connecte via /api/login_check ; le JWT obtenu est conservé en session et
 * envoyé en Bearer sur les actions d'administration.
 */
final class AdminApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserApiClient $user,
        #[Autowire(env: 'API_BASE_URL')]
        private readonly string $baseUrl,
    ) {
    }

    /** Connexion unique (déléguée) ; le jeton est partagé front + back-office. */
    public function login(string $email, string $password): bool
    {
        return $this->user->login($email, $password);
    }

    public function logout(): void
    {
        $this->user->logout();
    }

    /** Accès back-office = connecté ET ROLE_ADMIN (ce sont les rôles qui décident). */
    public function isLogged(): bool
    {
        return $this->user->isLogged() && $this->user->hasRole('ROLE_ADMIN');
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

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function editQuestion(int $id, string $text, ?string $title): array
    {
        return $this->send('PATCH', '/api/admin/questions/'.$id, ['text' => $text, 'title' => $title]);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function regenerateQuestion(int $id): array
    {
        // La génération LLM est lente : timeout large (le worker n'est pas impliqué ici).
        return $this->send('POST', '/api/admin/questions/'.$id.'/regenerate', [], 180);
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

    /** @return array<string,mixed> liste des articles wiki (BO) */
    public function wikiArticles(string $q = '', string $status = ''): array
    {
        $res = $this->send('GET', '/api/admin/wiki-articles?'.http_build_query(array_filter(['q' => $q, 'status' => $status])), null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'query' => $q, 'status' => $status];
    }

    /** @return array<string,mixed>|null détail d'un article wiki + révisions */
    public function wikiArticle(int $id): ?array
    {
        $res = $this->send('GET', '/api/admin/wiki-articles/'.$id, null);

        return $res['ok'] ? $res['data'] : null;
    }

    /** @return array<string,mixed> file des rapprochements de contenu (doublons/plagiat) à examiner */
    public function duplications(): array
    {
        $res = $this->send('GET', '/api/admin/duplications', null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'unreviewed' => 0];
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} décision comité sur un rapprochement */
    public function reviewDuplication(int $id, string $status): array
    {
        return $this->send('POST', '/api/admin/duplications/'.$id.'/review', ['status' => $status]);
    }

    /** @return array<string,mixed> file des études déposées proposées au corpus */
    public function corpusSubmissions(): array
    {
        $res = $this->send('GET', '/api/admin/corpus-submissions', null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'pending' => 0];
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} décision comité (approve|reject) */
    public function reviewCorpusSubmission(int $id, string $decision): array
    {
        return $this->send('POST', '/api/admin/corpus-submissions/'.$id.'/review', ['decision' => $decision]);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} lance la (re)génération d'article (async) */
    public function regenerateNodeArticle(int $id): array
    {
        return $this->send('POST', '/api/admin/nodes/'.$id.'/regenerate-article', []);
    }

    /**
     * Import direct d'une source PDF (multipart) → GROBID → corpus.
     *
     * @param array<string,string> $meta title (requis), doi, year, venue, abstract
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    public function uploadPdf(UploadedFile $pdf, array $meta): array
    {
        $token = $this->user->token();
        if (!\is_string($token)) {
            return ['ok' => false, 'status' => 401, 'data' => ['error' => 'Non authentifié.']];
        }

        $fields = array_filter([
            'title' => $meta['title'] ?? '',
            'doi' => $meta['doi'] ?? '',
            'year' => $meta['year'] ?? '',
            'venue' => $meta['venue'] ?? '',
            'abstract' => $meta['abstract'] ?? '',
        ], static fn (string $v): bool => '' !== $v);

        $form = new FormDataPart($fields + [
            'pdf' => DataPart::fromPath($pdf->getPathname(), $pdf->getClientOriginalName() ?: 'source.pdf', 'application/pdf'),
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl.'/api/admin/publications/upload-pdf', [
                'headers' => array_merge(['Authorization' => 'Bearer '.$token], $form->getPreparedHeaders()->toArray()),
                'body' => $form->bodyToIterable(),
                'timeout' => 240,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => ['error' => $e->getMessage()]];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data];
    }

    /**
     * @param list<string> $types filtre optionnel par type(s) de publication (scope la volumétrie)
     *
     * @return array<string,mixed> données du dashboard admin
     */
    public function adminStats(array $types = []): array
    {
        $path = '/api/admin/dashboard'.([] !== $types ? '?'.http_build_query(['types' => $types]) : '');
        $res = $this->send('GET', $path, null, 60);

        return $res['ok'] ? $res['data'] : [];
    }

    /** @return array<string,mixed> modèles disponibles sur le serveur d'inférence */
    public function models(): array
    {
        $res = $this->send('GET', '/api/admin/models', null);

        return $res['ok'] ? $res['data'] : ['models' => [], 'cloud' => [], 'default' => '', 'error' => 'Indisponible.'];
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

    /** @return array<string,mixed> demandes « Nous rejoindre » + rôles attribuables */
    public function joinRequests(string $status = ''): array
    {
        $res = $this->send('GET', '/api/admin/join-requests?'.http_build_query(array_filter(['status' => $status])), null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'availableRoles' => []];
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function promoteJoinRequest(int $id, string $role): array
    {
        return $this->send('POST', '/api/admin/join-requests/'.$id.'/promote', ['role' => $role]);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function rejectJoinRequest(int $id): array
    {
        return $this->send('POST', '/api/admin/join-requests/'.$id.'/reject', []);
    }

    /** Propositions de roadmap (vue back-office). @return array<string,mixed> */
    public function roadmapProposals(string $status = ''): array
    {
        $res = $this->send('GET', '/api/admin/roadmap-proposals?'.http_build_query(array_filter(['status' => $status])), null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'statuses' => []];
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function setRoadmapStatus(int $id, string $status): array
    {
        return $this->send('POST', '/api/admin/roadmap-proposals/'.$id.'/status', ['status' => $status]);
    }

    /** Inscriptions newsletter par cible (vue back-office). @return array<string,mixed> */
    public function newsletterSignups(string $audience = ''): array
    {
        $res = $this->send('GET', '/api/admin/newsletter-signups?'.http_build_query(array_filter(['audience' => $audience])), null);

        return $res['ok'] ? $res['data'] : ['items' => [], 'total' => 0];
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

    /** @return array<string,mixed> suivi de l'ingestion du snapshot OpenAlex + échantillon */
    public function snapshotStatus(): array
    {
        $res = $this->send('GET', '/api/admin/harvest/snapshot-status', null);

        return $res['ok'] ? $res['data'] : ['active' => false, 'progress' => null, 'derived' => null, 'samples' => []];
    }

    /** @return array<string,mixed> relance manuelle de l'ingestion du snapshot OpenAlex */
    public function snapshotRelaunch(): array
    {
        $res = $this->send('POST', '/api/admin/harvest/snapshot/relaunch', []);

        return $res['ok'] ? $res['data'] : ['ok' => false, 'message' => (string) ($res['data']['message'] ?? 'Échec de la relance.')];
    }

    /** @return array<string,mixed> indicateurs des drains (embeddings, placement, plein texte) */
    public function enrichmentStatus(): array
    {
        $res = $this->send('GET', '/api/admin/harvest/enrichment-status', null);

        return $res['ok'] ? $res['data'] : ['total_pub' => 0, 'embed_backlog' => 0, 'place_backlog' => 0, 'fulltext_queue' => 0];
    }

    /** @return array<string,mixed> graphe 3D (nœuds + liens de similarité) pour le visualiseur */
    public function graph3d(string $domain, int $limit): array
    {
        $qs = http_build_query(array_filter(['domain' => $domain, 'limit' => $limit]));
        $res = $this->send('GET', '/api/admin/graph3d?'.$qs, null);

        return $res['ok'] ? $res['data'] : ['nodes' => [], 'links' => [], 'domains' => [], 'meta' => ['count' => 0, 'error' => $res['data']['error'] ?? 'indisponible']];
    }

    /**
     * @param array<string,mixed>|null $body
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    private function send(string $method, string $path, ?array $body, int $timeout = 20): array
    {
        $token = $this->user->token();
        if (!\is_string($token)) {
            return ['ok' => false, 'status' => 401, 'data' => ['error' => 'Non authentifié.']];
        }

        $options = ['headers' => ['Authorization' => 'Bearer '.$token], 'timeout' => $timeout];
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
}
