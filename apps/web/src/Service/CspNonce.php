<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nonce CSP par requête. Stocké dans les attributs de la requête courante : sûr
 * même en mode worker FrankenPHP (le conteneur de services persiste, mais la
 * requête, elle, est neuve à chaque appel). Twig et le listener de réponse
 * lisent ainsi la MÊME valeur pour une requête donnée.
 */
final class CspNonce
{
    private const ATTR = '_csp_nonce';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function value(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return '';
        }

        $nonce = $request->attributes->get(self::ATTR);
        if (!\is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $request->attributes->set(self::ATTR, $nonce);
        }

        return $nonce;
    }
}
