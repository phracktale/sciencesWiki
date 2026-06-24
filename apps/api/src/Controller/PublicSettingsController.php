<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SettingsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réglages exposés PUBLIQUEMENT au front (visiteurs anonymes inclus). On n'expose
 * qu'une liste blanche minimale et non sensible (actuellement : le thème visuel).
 * Le front lit cet endpoint (en cache court) pour décider du thème à servir.
 */
final class PublicSettingsController
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    #[Route('/api/public-settings', name: 'api_public_settings', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'theme' => $this->settings->siteTheme(),
            'framed' => $this->settings->siteFramed(),
        ]);
    }
}
