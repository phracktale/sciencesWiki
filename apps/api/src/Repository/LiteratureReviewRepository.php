<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LiteratureReview;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LiteratureReview>
 */
class LiteratureReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LiteratureReview::class);
    }

    /**
     * @return list<LiteratureReview>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :u')->setParameter('u', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()->getResult();
    }
}
