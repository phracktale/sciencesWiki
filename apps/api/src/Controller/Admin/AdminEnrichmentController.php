<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Indicateurs des drains d'enrichissement pour le back-office (onglet
 * « Enrichissement » du suivi de moisson) : couverture embeddings, file de
 * placement, file plein texte (GROBID). Requêtes volontairement bon marché :
 * backlogs via index partiels (idx_pub_need_embedding / idx_pub_need_placement),
 * totaux approximés via pg_class.reltuples, file plein texte via
 * messenger_messages. ROLE_ADMIN (firewall /api/admin).
 */
final class AdminEnrichmentController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/admin/harvest/enrichment-status', name: 'admin_enrichment_status', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $conn = $this->em->getConnection();

        $row = $conn->fetchAssociative(
            "SELECT
                (SELECT reltuples::bigint FROM pg_class WHERE relname = 'publication')        AS total_pub,
                (SELECT reltuples::bigint FROM pg_class WHERE relname = 'publication_chunk')   AS total_chunks,
                (SELECT count(*) FROM publication WHERE embedding IS NULL)                     AS embed_backlog,
                (SELECT count(*) FROM publication
                    WHERE embedding IS NOT NULL AND processing_status = 'normalized')          AS place_backlog,
                (SELECT count(*) FROM messenger_messages WHERE queue_name = 'fulltext')        AS fulltext_queue,
                (SELECT count(*) FROM messenger_messages WHERE queue_name = 'failed')          AS failed_queue"
        ) ?: [];

        $totalPub = (int) ($row['total_pub'] ?? 0);
        $embedBacklog = (int) ($row['embed_backlog'] ?? 0);
        $embedded = max(0, $totalPub - $embedBacklog);

        return new JsonResponse([
            'at' => gmdate('c'),
            'total_pub' => $totalPub,                       // approx (reltuples)
            'total_chunks' => (int) ($row['total_chunks'] ?? 0), // approx
            'embedded' => $embedded,                         // approx (total - backlog)
            'embed_backlog' => $embedBacklog,                // exact (index partiel)
            'embed_pct' => $totalPub > 0 ? round($embedded / $totalPub * 100, 1) : 0.0,
            'place_backlog' => (int) ($row['place_backlog'] ?? 0), // exact (index partiel)
            'fulltext_queue' => (int) ($row['fulltext_queue'] ?? 0),
            'failed_queue' => (int) ($row['failed_queue'] ?? 0),
        ]);
    }
}
