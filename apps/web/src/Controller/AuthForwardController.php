<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\UserApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Forward-auth pour Open WebUI (SSO « trusted header », cf.
 * docs/spec-openwebui-rag.md). Appelé en sous-requête par Heimdall (nginx
 * auth_request) : le front détient déjà la session (JWT). Si l'utilisateur est
 * connecté, renvoie 200 + l'e-mail/nom dans des en-têtes que le proxy recopie
 * vers Open WebUI ; sinon 401 (le proxy redirige vers la connexion).
 *
 * Sécurité : ces en-têtes ne font autorité que parce qu'Open WebUI est joignable
 * UNIQUEMENT via Heimdall, qui EFFACE tout X-SW-Auth-* entrant et ne pose que la
 * valeur issue d'ici.
 */
final class AuthForwardController extends AbstractController
{
    public function __construct(private readonly UserApiClient $user)
    {
    }

    #[Route('/auth/openwebui', name: 'auth_openwebui', methods: ['GET'])]
    public function openwebui(): Response
    {
        if (!$this->user->isLogged()) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }
        $email = trim((string) ($this->user->me()['email'] ?? ''));
        if ('' === $email) {
            // Open WebUI indexe les comptes par e-mail : sans e-mail, pas d'accès.
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $response = new Response('', Response::HTTP_OK);
        $response->headers->set('X-SW-Auth-Email', $email);
        $response->headers->set('X-SW-Auth-Name', $this->user->displayName());

        return $response;
    }
}
