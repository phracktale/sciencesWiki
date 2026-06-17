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
    public function renameNode(int $id, string $label, ?string $description): array
    {
        return $this->send('PATCH', '/api/admin/nodes/'.$id, ['label' => $label, 'description' => $description]);
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

    /**
     * @param array<string,mixed> $body
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    private function send(string $method, string $path, array $body): array
    {
        $token = $this->session()->get(self::SESSION_KEY);
        if (!\is_string($token)) {
            return ['ok' => false, 'status' => 401, 'data' => ['error' => 'Non authentifié.']];
        }

        try {
            $response = $this->httpClient->request($method, $this->baseUrl.$path, [
                'headers' => ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'],
                'json' => $body,
                'timeout' => 15,
            ]);
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
