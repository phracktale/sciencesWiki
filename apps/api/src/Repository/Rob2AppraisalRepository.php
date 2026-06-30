<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publication;
use App\Entity\Rob2Appraisal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rob2Appraisal>
 */
final class Rob2AppraisalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rob2Appraisal::class);
    }

    public function findForPublication(Publication $publication): ?Rob2Appraisal
    {
        return $this->findOneBy(['publication' => $publication]);
    }

    /** Purge avant ré-évaluation (idempotence). */
    public function deleteForPublication(Publication $publication): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.publication = :p')->setParameter('p', $publication)
            ->getQuery()->execute();
    }
}
