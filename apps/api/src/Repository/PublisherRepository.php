<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publisher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Publisher>
 */
class PublisherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Publisher::class);
    }

    public function findOneByOpenAlexId(string $openAlexId): ?Publisher
    {
        return $this->findOneBy(['openAlexId' => $openAlexId]);
    }
}
