<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IngestionJob;
use App\Entity\Source;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IngestionJob>
 */
class IngestionJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestionJob::class);
    }

    /**
     * Dernier curseur atteint pour une source (reprise de la moisson incrémentale).
     */
    public function findLastEndCursor(Source $source): ?string
    {
        $job = $this->findOneBy(['source' => $source], ['startedAt' => 'DESC']);

        return $job?->getEndCursor();
    }

    /**
     * Curseur de reprise pour une rubrique donnée (moisson par concept). On reprend
     * la pagination là où la dernière exécution réussie s'est arrêtée. Si la dernière
     * exécution avait épuisé le jeu de résultats (curseur nul), on repart de zéro
     * (re-balayage, le dédup par DOI évite les doublons).
     */
    public function findResumeCursorForRubric(string $rubricSlug): ?string
    {
        $cursor = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT end_cursor FROM ingestion_job
             WHERE query->>'rubric' = :slug AND status IN ('ok', 'partial')
             ORDER BY started_at DESC
             LIMIT 1",
            ['slug' => $rubricSlug],
        )->fetchOne();

        return false === $cursor || null === $cursor ? null : (string) $cursor;
    }
}
