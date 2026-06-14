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
}
