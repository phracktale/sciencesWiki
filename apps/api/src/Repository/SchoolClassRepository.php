<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchoolClass;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolClass>
 */
final class SchoolClassRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolClass::class);
    }

    /** @return list<SchoolClass> classes créées par cet enseignant (récentes d'abord) */
    public function findByTeacher(User $teacher): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.teacher = :t')->setParameter('t', $teacher)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()->getResult();
    }
}
