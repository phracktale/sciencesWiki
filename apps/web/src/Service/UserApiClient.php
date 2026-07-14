<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Identité publique (rédacteurs, comité, modérateurs, admins) côté front : login
 * via l'API (JWT en session) + rôles récupérés sur /api/me. Sert à afficher les
 * outils d'édition et à relayer les écritures vers l'API avec le jeton de l'utilisateur.
 *
 * NB : la sécurité réelle est appliquée côté API (voters) ; ici les contrôles de
 * rôle ne servent qu'à l'affichage (UX).
 */
final class UserApiClient
{
    // Jeton de session UNIQUE (front + back-office) : les rôles décident des accès.
    private const TOKEN_KEY = 'auth_jwt';
    private const ME_KEY = 'auth_me';

    /** Hiérarchie des rôles (miroir de security.yaml côté API). */
    private const HIERARCHY = [
        'ROLE_ADMIN' => ['ROLE_MODERATEUR', 'ROLE_COMITE', 'ROLE_RESEARCHER', 'ROLE_TEACHER'],
        'ROLE_MODERATEUR' => ['ROLE_REDACTEUR'],
        'ROLE_COMITE' => ['ROLE_REDACTEUR'],
        'ROLE_REDACTEUR' => ['ROLE_AUTEUR'],
        'ROLE_RESEARCHER' => ['ROLE_AUTEUR'],
        'ROLE_TEACHER' => ['ROLE_STUDENT'],
        'ROLE_STUDENT' => ['ROLE_USER'],
        'ROLE_AUTEUR' => ['ROLE_USER'],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RequestStack $requestStack,
        #[Autowire(env: 'API_BASE_URL')]
        private readonly string $baseUrl,
    ) {
    }

    public function login(string $email, string $password): bool
    {
        try {
            $data = $this->httpClient->request('POST', $this->baseUrl.'/api/login_check', [
                'json' => ['email' => $email, 'password' => $password],
                'timeout' => 10,
            ])->toArray(false);
            $token = $data['token'] ?? null;
            if (!\is_string($token) || '' === $token) {
                return false;
            }
            // toArray() (sans false) LÈVE sur réponse non-2xx : si /api/me échoue (api
            // instable, ex. redéploiement), on ne stocke PAS une session sans rôles
            // (qui provoquerait des 403). Le login échoue proprement → réessai.
            $me = $this->httpClient->request('GET', $this->baseUrl.'/api/me', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'timeout' => 10,
            ])->toArray();
        } catch (\Throwable) {
            return false;
        }

        // Garde-fou : ne jamais ouvrir une session sans rôles (source de 403 silencieux).
        if (!isset($me['roles']) || !\is_array($me['roles'])) {
            return false;
        }

        $session = $this->session();
        $session->set(self::TOKEN_KEY, $token);
        $session->set(self::ME_KEY, $me);

        return true;
    }

    /**
     * Inscription self-service (chercheur/enseignant/élève) + connexion immédiate
     * (le JWT renvoyé par l'API est stocké en session, comme login()).
     *
     * @return array{ok:bool,error:?string}
     */
    public function register(string $email, string $password, string $realName, string $role): array
    {
        try {
            $resp = $this->httpClient->request('POST', $this->baseUrl.'/api/register', [
                'json' => ['email' => $email, 'password' => $password, 'realName' => $realName, 'role' => $role],
                'timeout' => 15,
            ]);
            $data = $resp->toArray(false);
            $status = $resp->getStatusCode();
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Service indisponible, réessayez.'];
        }
        $token = $data['token'] ?? null;
        if ($status >= 300 || !\is_string($token) || '' === $token) {
            return ['ok' => false, 'error' => (string) ($data['error'] ?? 'Inscription refusée.')];
        }

        try {
            $me = $this->httpClient->request('GET', $this->baseUrl.'/api/me', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'timeout' => 10,
            ])->toArray(false);
        } catch (\Throwable) {
            $me = ['email' => $email, 'roles' => $data['roles'] ?? []];
        }
        $session = $this->session();
        $session->set(self::TOKEN_KEY, $token);
        $session->set(self::ME_KEY, $me);

        return ['ok' => true, 'error' => null];
    }

    public function logout(): void
    {
        $this->session()->remove(self::TOKEN_KEY);
        $this->session()->remove(self::ME_KEY);
    }

    public function isLogged(): bool
    {
        return \is_string($this->session()->get(self::TOKEN_KEY));
    }

    /** Jeton JWT courant (partagé front + back-office), ou null. */
    public function token(): ?string
    {
        $t = $this->session()->get(self::TOKEN_KEY);

        return \is_string($t) ? $t : null;
    }

    /** @return array<string,mixed> */
    public function me(): array
    {
        $me = $this->session()->get(self::ME_KEY);

        return \is_array($me) ? $me : [];
    }

    /**
     * Recharge le profil depuis l'API et met à jour la session (après une édition de profil,
     * ou pour compléter une session ancienne dont le `me` n'a pas tous les champs).
     *
     * @return array<string,mixed>
     */
    public function refreshMe(): array
    {
        $token = $this->token();
        if (null === $token) {
            return $this->me();
        }
        try {
            $me = $this->httpClient->request('GET', $this->baseUrl.'/api/me', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'timeout' => 10,
            ])->toArray();
        } catch (\Throwable) {
            return $this->me();
        }
        if (isset($me['roles']) && \is_array($me['roles'])) {
            $this->session()->set(self::ME_KEY, $me);
        }

        return $this->me();
    }

    public function displayName(): string
    {
        $me = $this->me();

        return (string) ($me['pseudo'] ?? $me['realName'] ?? $me['email'] ?? 'Utilisateur');
    }

    /** Rôles effectifs (hiérarchie dépliée). @return list<string> */
    public function roles(): array
    {
        $raw = $this->me()['roles'] ?? [];
        $roles = [];
        $stack = array_values(array_filter(array_map('strval', \is_array($raw) ? $raw : [])));
        while ([] !== $stack) {
            $r = array_pop($stack);
            if (isset($roles[$r])) {
                continue;
            }
            $roles[$r] = true;
            foreach (self::HIERARCHY[$r] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_keys($roles);
    }

    public function hasRole(string $role): bool
    {
        return $this->isLogged() && \in_array($role, $this->roles(), true);
    }

    public function canEdit(): bool
    {
        return $this->hasRole('ROLE_REDACTEUR');
    }

    public function canValidate(): bool
    {
        return $this->hasRole('ROLE_MODERATEUR') || $this->hasRole('ROLE_COMITE') || $this->hasRole('ROLE_ADMIN');
    }

    /** Accès à l'espace chercheur (outils de recherche). */
    public function canResearch(): bool
    {
        return $this->hasRole('ROLE_RESEARCHER');
    }

    /** Accès à l'espace enseignant (gestion de classe). */
    public function canTeach(): bool
    {
        return $this->hasRole('ROLE_TEACHER');
    }

    /** Peut déclencher l'évaluation AXIS à la demande (chercheur / enseignant / élève). */
    public function canUseAxis(): bool
    {
        return $this->hasRole('ROLE_RESEARCHER') || $this->hasRole('ROLE_TEACHER') || $this->hasRole('ROLE_STUDENT');
    }

    /**
     * Relaie une écriture vers l'API avec le JWT de l'utilisateur.
     *
     * @param array<string,mixed>|null $body
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    public function send(string $method, string $path, ?array $body = null, int $timeout = 30): array
    {
        $token = $this->session()->get(self::TOKEN_KEY);
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

    /**
     * Outils d'évaluation critique applicables, par étude (DOI). Classe en asynchrone
     * les études pas encore détectées. @param list<string> $dois @return array<string,mixed>
     */
    public function appraisalTools(array $dois): array
    {
        $res = $this->send('POST', '/api/me/appraisal/tools', ['dois' => $dois]);

        return $res['ok'] ? $res['data'] : ['results' => []];
    }

    /**
     * Analyses méthodologiques DÉJÀ calculées pour une étude (par id), tous statuts
     * confondus — réservé aux rôles outils (vue déclencheur). @return array<string,mixed>
     */
    public function existingAppraisals(int $id): array
    {
        $out = [];
        foreach (['axis', 'rob2', 'amstar2', 'mmat'] as $tool) {
            $res = $this->send('GET', '/api/me/'.$tool.'/status?id='.$id);
            if (($res['data']['status'] ?? null) === 'ready') {
                $out[$tool] = $res['data'];
            }
        }

        return $out;
    }

    // --------------------------- Espace pédagogique ---------------------------

    /** Classes de l'enseignant connecté (effectif + invitations en attente). @return array<string,mixed> */
    public function myClasses(): array
    {
        $res = $this->send('GET', '/api/me/classes');

        return $res['ok'] ? $res['data'] : ['classes' => []];
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function createClass(string $name): array
    {
        return $this->send('POST', '/api/me/classes', ['name' => $name]);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function inviteStudent(int $classId, string $email): array
    {
        return $this->send('POST', '/api/me/classes/'.$classId.'/invite', ['email' => $email]);
    }

    /** Classes rejointes par l'élève connecté. @return array<string,mixed> */
    public function joinedClasses(): array
    {
        $res = $this->send('GET', '/api/me/class/joined');

        return $res['ok'] ? $res['data'] : ['classes' => []];
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function joinClass(string $token): array
    {
        return $this->send('POST', '/api/me/class/join', ['token' => $token]);
    }

    /** Aperçu PUBLIC d'une invitation (sans connexion). @return array<string,mixed>|null */
    public function classInvitationPreview(string $token): ?array
    {
        try {
            return $this->httpClient->request('GET', $this->baseUrl.'/api/class/join/'.rawurlencode($token), ['timeout' => 10])->toArray(false);
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------ Dépôt d'étude (évaluation critique) -------------

    /**
     * Dépose un PDF d'étude (multipart) pour évaluation critique. L'étude reste
     * privée jusqu'à validation comité.
     *
     * @param array<string,string> $meta title (requis), doi, year, venue, abstract
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    public function uploadStudy(UploadedFile $pdf, array $meta): array
    {
        $token = $this->token();
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
            'pdf' => DataPart::fromPath($pdf->getPathname(), $pdf->getClientOriginalName() ?: 'etude.pdf', 'application/pdf'),
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl.'/api/me/study/upload', [
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

    /** Demande l'ajout d'une étude déposée au corpus (validation comité). @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function submitStudyToCorpus(int $id, ?string $note = null): array
    {
        return $this->send('POST', '/api/me/study/'.$id.'/submit-to-corpus', ['note' => (string) $note]);
    }

    /** Études déposées par l'utilisateur (espace « mes études »). @return array<string,mixed> */
    public function myStudies(): array
    {
        $res = $this->send('GET', '/api/me/studies');

        return $res['ok'] ? $res['data'] : ['items' => []];
    }

    private function session(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        return $this->requestStack->getSession();
    }
}
