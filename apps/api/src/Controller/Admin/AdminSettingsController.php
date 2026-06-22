<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\SettingsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lecture/édition des paramètres IA (ROLE_ADMIN) : prompt système, longueur de
 * réponse, température, nombre de sources, modèle.
 */
final class AdminSettingsController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly \App\Service\ActivityLogger $activity,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
    ) {
    }

    #[Route('/api/admin/settings', name: 'admin_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->settings->editable());
    }

    #[Route('/api/admin/settings', name: 'admin_settings_save', methods: ['PUT', 'POST'])]
    public function save(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $values = [];
        foreach ($data as $k => $v) {
            $values[(string) $k] = (string) $v;
        }
        $this->settings->setMany($values);

        $this->activity->log('settings', 'update', $this->security->getUser()?->getUserIdentifier() ?? 'admin', 'Paramètres modifiés : '.implode(', ', array_keys($values)), ['keys' => array_keys($values)]);

        return new JsonResponse($this->settings->editable());
    }
}
