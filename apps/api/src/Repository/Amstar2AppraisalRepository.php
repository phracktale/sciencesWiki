<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Amstar2Appraisal;
use App\Entity\Publication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Amstar2Appraisal>
 */
final class Amstar2AppraisalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Amstar2Appraisal::class);
    }

    public function findForPublication(Publication $publication): ?Amstar2Appraisal
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
