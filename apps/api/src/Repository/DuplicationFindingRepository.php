<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DuplicationFinding;
use App\Entity\Publication;
use App\Enum\FindingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DuplicationFinding>
 */
final class DuplicationFindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DuplicationFinding::class);
    }

    public function findPair(Publication $source, Publication $target): ?DuplicationFinding
    {
        return $this->findOneBy(['source' => $source, 'target' => $target]);
    }

    /**
     * File de revue comité : rapprochements à examiner, plus recouvrants d'abord.
     *
     * @return list<DuplicationFinding>
     */
    public function unreviewed(int $limit = 50): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.status = :s')
            ->setParameter('s', FindingStatus::Unreviewed->value)
            ->orderBy('f.overlapRatio', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    public function countUnreviewed(): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.status = :s')
            ->setParameter('s', FindingStatus::Unreviewed->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
