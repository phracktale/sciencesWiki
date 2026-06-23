<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoadmapProposal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoadmapProposal>
 */
class RoadmapProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoadmapProposal::class);
    }
}
