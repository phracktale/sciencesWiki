<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Mode maintenance : tant que le fichier-drapeau var/maintenance.lock existe, TOUTES
 * les requêtes reçoivent l'écran de maintenance (503) — court-circuit avant routing,
 * contrôleurs et base de données. Basculable SANS redéploiement (touch/rm du fichier).
 * Un·e admin peut prévisualiser le site réel via ?wake=<token> (pose un cookie).
 */
final class MaintenanceSubscriber implements EventSubscriberInterface
{
    /** Jeton de contournement (prévisualisation du site pendant la maintenance). */
    private const BYPASS_TOKEN = 'white-rabbit-8f3c';

    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorité très haute : avant le routeur (32) et le pare-feu.
        return [KernelEvents::REQUEST => ['onRequest', 4096]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isOn()) {
            return;
        }

        $request = $event->getRequest();
        // Contournement admin (prévisualisation du site réel pendant la maintenance).
        if (self::BYPASS_TOKEN === $request->query->get('wake')
            || self::BYPASS_TOKEN === $request->cookies->get('wake')) {
            return;
        }

        $html = $this->twig->render('maintenance.html.twig');
        $event->setResponse(new Response($html, Response::HTTP_SERVICE_UNAVAILABLE, [
            'Retry-After' => '3600',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]));
    }

    private function isOn(): bool
    {
        $flag = $this->projectDir.'/var/maintenance.lock';
        // Nécessaire en mode worker (FrankenPHP) : le cache de stat survit aux requêtes.
        clearstatcache(true, $flag);

        return is_file($flag);
    }
}
