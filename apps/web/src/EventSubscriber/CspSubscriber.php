<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\CspNonce;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pose la Content-Security-Policy sur les réponses HTML du front, avec un NONCE
 * par requête : plus de 'unsafe-inline' sur les scripts (durcissement XSS).
 * Les styles inline restent autorisés (attributs style="" omniprésents, non
 * couvrables par nonce, risque faible). Mercure + /api sont en même origine.
 */
final class CspSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CspNonce $nonce)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        $contentType = (string) $response->headers->get('Content-Type', 'text/html');
        // Inutile sur les réponses non-HTML (JSON, flux SSE, fichiers).
        if ('' !== $contentType && !str_contains($contentType, 'text/html')) {
            return;
        }

        $nonce = $this->nonce->value();
        $csp = \sprintf(
            "default-src 'self'; "
            ."script-src 'self' 'nonce-%s' https://analytics.phracktale.com; "
            ."style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: https:; "
            ."font-src 'self' data:; "
            ."connect-src 'self' https://analytics.phracktale.com; "
            ."frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'",
            $nonce,
        );
        $response->headers->set('Content-Security-Policy', $csp);
    }
}
