<?php

declare(strict_types=1);

namespace Analyses\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de vie du module. `/health` est public (sonde) ; `/` (analys_home)
 * exige un JWT SciencesWiki valide et renvoie l'identité reconstruite depuis les
 * claims — ce qui prouve la validation JWT locale + l'accès à la base partagée.
 */
final class HealthController extends AbstractController
{
    #[Route('/health', name: 'analys_health', methods: ['GET'])]
    public function health(Connection $db): JsonResponse
    {
        // Vérifie la connexion à la base SW partagée (sans écrire).
        $dbOk = false;
        try {
            $dbOk = 1 === (int) $db->fetchOne('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        return new JsonResponse([
            'module' => 'analyses',
            'prefix' => 'ANALYS',
            'status' => $dbOk ? 'ok' : 'degraded',
            'database' => $dbOk ? 'connected' : 'unreachable',
        ], $dbOk ? 200 : 503);
    }

    #[Route('/', name: 'analys_home', methods: ['GET'])]
    public function home(): JsonResponse
    {
        $user = $this->getUser();

        return new JsonResponse([
            'module' => 'analyses',
            'message' => 'JWT SciencesWiki validé.',
            'identity' => $user?->getUserIdentifier(),
            'roles' => $user?->getRoles() ?? [],
        ]);
    }
}
