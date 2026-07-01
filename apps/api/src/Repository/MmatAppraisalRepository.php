<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MmatAppraisal;
use App\Entity\Publication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MmatAppraisal>
 */
final class MmatAppraisalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MmatAppraisal::class);
    }

    public function findForPublication(Publication $publication): ?MmatAppraisal
    {
        return $this->findOneBy(['publication' => $publication]);
    }

    public function deleteForPublication(Publication $publication): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.publication = :p')->setParameter('p', $publication)
            ->getQuery()->execute();
    }
}
