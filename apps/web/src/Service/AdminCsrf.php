<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Protection CSRF légère des formulaires d'administration : un jeton par session,
 * injecté dans les formulaires (champ _csrf) et vérifié à chaque POST.
 */
final class AdminCsrf
{
    private const KEY = 'admin_csrf';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getToken(): string
    {
        $session = $this->requestStack->getSession();
        $token = $session->get(self::KEY);
        if (!\is_string($token)) {
            $token = bin2hex(random_bytes(16));
            $session->set(self::KEY, $token);
        }

        return $token;
    }

    public function isValid(Request $request): bool
    {
        $submitted = (string) $request->request->get('_csrf', '');

        return '' !== $submitted && hash_equals($this->getToken(), $submitted);
    }
}
