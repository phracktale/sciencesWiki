<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlacementSuggestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlacementSuggestion>
 */
class PlacementSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlacementSuggestion::class);
    }
}
