<?php

declare(strict_types=1);

namespace Analyses\Controller;

use Analyses\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration GLOBALE du module (section admin, ROLE_ADMIN — cf. manifeste et
 * framework SPECS §7.3). Réglages : modèles LLM, seuil de validation humaine,
 * référentiels activés. Appliqués à chaud (sans redéploiement).
 */
final class AdminController extends AbstractController
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    #[Route('/admin/settings', name: 'analys_admin_settings', methods: ['GET'])]
    public function read(): JsonResponse
    {
        return new JsonResponse([
            'settings' => $this->settings->editable(),
            'editable_keys' => SettingsService::EDITABLE,
            'hint' => [
                'analys.frameworks.enabled' => 'liste d\'ids séparés par des virgules (vide = tous) : axis, rob2, amstar2, mmat, strobe, consort, prisma',
            ],
        ]);
    }

    #[Route('/admin/settings', name: 'analys_admin_settings_update', methods: ['PUT', 'POST'])]
    public function update(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '[]', true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'Corps JSON invalide.'], 400);
        }

        $this->settings->setMany($payload);

        return new JsonResponse(['ok' => true, 'settings' => $this->settings->editable()]);
    }
}
