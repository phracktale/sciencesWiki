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
            // Iframe de l'assistant IA (Open WebUI) sur le sous-domaine chat.
            ."frame-src 'self' https://chat.scienceswiki.eu; "
            ."frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'",
            $nonce,
        );
        $response->headers->set('Content-Security-Policy', $csp);

        // Socle d'en-têtes de sécurité au niveau applicatif (défense en profondeur) :
        // nginx/Heimdall les pose déjà en prod, mais l'app reste protégée si un jour
        // elle est servie sans ce proxy. On n'écrase pas une valeur déjà posée en amont.
        $h = $response->headers;
        $h->has('X-Content-Type-Options') || $h->set('X-Content-Type-Options', 'nosniff');
        $h->has('X-Frame-Options') || $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->has('Referrer-Policy') || $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // HSTS : laissé à nginx (il connaît le contexte TLS réel). Ne pas le poser ici
        // en HTTP clair (FrankenPHP écoute en :80 derrière le proxy).
    }
}
