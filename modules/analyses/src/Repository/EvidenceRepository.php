<?php

declare(strict_types=1);

namespace Analyses\Repository;

use Analyses\Entity\Evidence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<Evidence>
 */
class EvidenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evidence::class);
    }

    /** @return list<Evidence> */
    public function findForAssessment(Ulid $assessmentId): array
    {
        return $this->findBy(['assessmentId' => $assessmentId]);
    }
}
