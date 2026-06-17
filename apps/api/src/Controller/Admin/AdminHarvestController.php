<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Suivi de la moisson (ROLE_ADMIN) : état de chaque worker/rubrique (en attente,
 * en cours, terminé, en erreur), nombre de publications moissonnées, et remontée
 * explicite des erreurs — notamment le dépassement des limites de l'API OpenAlex
 * (quota quotidien), qui est journalisé dans IngestionJob.log par HarvestRunner.
 */
final class AdminHarvestController
{
    private const DAILY_CAP = 100000;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/admin/harvest/status', name: 'admin_harvest_status', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $conn = $this->em->getConnection();

        // --- Travaux de moisson par rubrique (40 plus récents) ---
        // La requête JSON de l'IngestionJob porte 'rubric' => slug pour les
        // moissons ciblées lancées depuis le back-office.
        $rows = $conn->executeQuery(
            "SELECT j.id, j.query->>'rubric' AS rubric, tn.label AS label,
                    j.started_at, j.finished_at, j.processed, j.created, j.errors,
                    j.status, j.log
             FROM ingestion_job j
             LEFT JOIN tree_node tn ON tn.slug = (j.query->>'rubric')
             WHERE j.query->>'rubric' IS NOT NULL
             ORDER BY j.started_at DESC
             LIMIT 40"
        )->fetchAllAssociative();

        $jobs = array_map(static function (array $r): array {
            $log = $r['log'] ?? null;
            // Dépassement des limites OpenAlex : quota quotidien interne, ou
            // réponse HTTP 429 / rate limit renvoyée par l'API.
            $isQuota = null !== $log && preg_match('/quota|rate ?limit|429|too many/i', (string) $log) === 1;

            return [
                'id' => (int) $r['id'],
                'rubric' => $r['rubric'],
                'label' => $r['label'] ?? $r['rubric'],
                'startedAt' => $r['started_at'],
                'finishedAt' => $r['finished_at'],
                'processed' => (int) $r['processed'],
                'created' => (int) $r['created'],
                'errors' => (int) $r['errors'],
                'status' => $r['status'],
                'log' => $log,
                'rateLimited' => $isQuota,
            ];
        }, $rows);

        // --- File d'attente Messenger (moissons pas encore prises par un worker) ---
        $queued = 0;
        try {
            $queued = (int) $conn->executeQuery(
                "SELECT count(*) FROM messenger_messages
                 WHERE delivered_at IS NULL AND body LIKE '%HarvestRubric%'"
            )->fetchOne();
        } catch (\Throwable) {
            // Table absente (autre transport) : on laisse 0.
        }

        // --- Quota quotidien OpenAlex (compteur partagé géré par OpenAlexThrottle) ---
        $today = date('Y-m-d');
        $used = (int) ($conn->executeQuery(
            "SELECT value FROM setting WHERE name = :n",
            ['n' => 'openalex.count.'.$today],
        )->fetchOne() ?: 0);

        return new JsonResponse([
            'jobs' => $jobs,
            'queued' => $queued,
            'running' => array_values(array_filter($jobs, static fn (array $j): bool => 'running' === $j['status'])),
            'openalex' => [
                'date' => $today,
                'used' => $used,
                'cap' => self::DAILY_CAP,
                'exhausted' => $used >= self::DAILY_CAP,
            ],
        ]);
    }
}
