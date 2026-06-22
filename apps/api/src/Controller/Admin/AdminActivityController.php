<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ActivityLogRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Journal d'audit (ROLE_ADMIN) : historique paginé et filtrable des événements
 * (moissons, questions humaines/IA, modifications, utilisateurs, réglages).
 */
final class AdminActivityController
{
    public function __construct(private readonly ActivityLogRepository $logs)
    {
    }

    #[Route('/api/admin/activity', name: 'admin_activity', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $category = trim((string) $request->query->get('category', ''));
        $page = max(1, (int) $request->query->get('page', '1'));
        $data = $this->logs->page($category, $page);

        return new JsonResponse([
            'items' => array_map(static fn ($l): array => [
                'occurredAt' => $l->getOccurredAt()->format('Y-m-d H:i:s'),
                'category' => $l->getCategory(),
                'action' => $l->getAction(),
                'actor' => $l->getActor(),
                'summary' => $l->getSummary(),
                'ip' => $l->getIp(),
            ], $data['items']),
            'total' => $data['total'],
            'page' => $data['page'],
            'pages' => $data['pages'],
            'category' => $data['category'],
            'categories' => $this->logs->categories(),
        ]);
    }
}
