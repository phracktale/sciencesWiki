<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nonce CSP STABLE par session.
 *
 * Pourquoi par session et non par requête : Turbo Drive remplace le <body> sans
 * recharger le document → la CSP active reste celle du premier chargement. Un
 * nonce par requête ne correspondrait donc plus aux scripts réinjectés par Turbo
 * (ils seraient bloqués). Un nonce stable sur la durée de la session reste
 * imprévisible (donc protège contre l'injection) tout en étant compatible Turbo.
 * Repli per-requête si aucune session n'est disponible.
 */
final class CspNonce
{
    private const KEY = '_csp_nonce';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function value(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return '';
        }

        try {
            $session = $request->getSession();
            $nonce = $session->get(self::KEY);
            if (!\is_string($nonce)) {
                $nonce = self::generate();
                $session->set(self::KEY, $nonce);
            }

            return $nonce;
        } catch (\Throwable) {
            // Pas de session (ex. requête sans contexte session) : repli per-requête.
            $nonce = $request->attributes->get(self::KEY);
            if (!\is_string($nonce)) {
                $nonce = self::generate();
                $request->attributes->set(self::KEY, $nonce);
            }

            return $nonce;
        }
    }

    private static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
