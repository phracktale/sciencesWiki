<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActivityLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Enregistre les événements dans le journal d'audit (table activity_log).
 * Volontairement tolérant aux pannes : une erreur de journalisation ne doit
 * jamais interrompre l'action métier.
 */
final class ActivityLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string,mixed>|null $context */
    public function log(string $category, string $action, string $actor = 'system', ?string $summary = null, ?array $context = null, ?string $ip = null): void
    {
        try {
            $this->em->persist(new ActivityLog($category, $action, $actor, $summary, $context, $ip));
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Journalisation d\'audit échouée : '.$e->getMessage(), ['category' => $category, 'action' => $action]);
        }
    }
}
