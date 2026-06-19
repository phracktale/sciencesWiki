<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\ActivityLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Génère un lien sécurisé (jeton à usage unique, expirant à 90 jours) permettant
 * aux auteurs d'un article payant de déposer leur version auteur (PDF) → RAG.
 * ROLE_ADMIN. Le lien est ensuite communiqué aux auteurs (e-mail / mailto).
 */
final class AdminContributionController
{
    public function __construct(
        private readonly Connection $conn,
        private readonly ActivityLogger $activity,
    ) {
    }

    #[Route('/api/admin/publications/{id}/contribution-token', name: 'admin_contribution_token', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(int $id): JsonResponse
    {
        $pub = $this->conn->executeQuery('SELECT title FROM publication WHERE id = :id', ['id' => $id])->fetchAssociative();
        if (false === $pub) {
            return new JsonResponse(['error' => 'Publication introuvable.'], 404);
        }

        $token = bin2hex(random_bytes(24));
        $this->conn->executeStatement(
            "INSERT INTO contribution_token (token, publication_id, created_at, expires_at)
             VALUES (:t, :p, now(), now() + interval '90 days')",
            ['t' => $token, 'p' => $id],
        );
        $this->activity->log('contribution', 'token_created', 'admin', \sprintf('Lien de dépôt auteur généré : « %s »', (string) $pub['title']), ['publicationId' => $id]);

        return new JsonResponse(['token' => $token, 'path' => '/fr/contribuer/'.$token]);
    }
}
