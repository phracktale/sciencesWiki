<?php

declare(strict_types=1);

namespace Analyses\Repository;

use Analyses\Entity\Assessment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Assessment>
 */
class AssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assessment::class);
    }

    /**
     * Classeur d'un utilisateur : ses évaluations, les plus récentes d'abord.
     *
     * @return list<Assessment>
     */
    public function findForUser(string $requestedBy, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.requestedBy = :u')
            ->setParameter('u', $requestedBy)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }
}
