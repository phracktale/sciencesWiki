<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
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
        'ROLE_ADMIN' => ['ROLE_MODERATEUR', 'ROLE_COMITE', 'ROLE_RESEARCHER'],
        'ROLE_MODERATEUR' => ['ROLE_REDACTEUR'],
        'ROLE_COMITE' => ['ROLE_REDACTEUR'],
        'ROLE_REDACTEUR' => ['ROLE_AUTEUR'],
        'ROLE_RESEARCHER' => ['ROLE_AUTEUR'],
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
            $me = $this->httpClient->request('GET', $this->baseUrl.'/api/me', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'timeout' => 10,
            ])->toArray(false);
        } catch (\Throwable) {
            return false;
        }

        $session = $this->session();
        $session->set(self::TOKEN_KEY, $token);
        $session->set(self::ME_KEY, $me);

        return true;
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

    /**
     * Relaie une écriture vers l'API avec le JWT de l'utilisateur.
     *
     * @param array<string,mixed>|null $body
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    public function send(string $method, string $path, ?array $body = null): array
    {
        $token = $this->session()->get(self::TOKEN_KEY);
        if (!\is_string($token)) {
            return ['ok' => false, 'status' => 401, 'data' => ['error' => 'Non authentifié.']];
        }
        $options = ['headers' => ['Authorization' => 'Bearer '.$token], 'timeout' => 30];
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
