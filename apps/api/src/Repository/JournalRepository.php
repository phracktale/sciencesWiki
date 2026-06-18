<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Journal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Journal>
 */
class JournalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Journal::class);
    }

    public function findOneByOpenAlexId(string $openAlexId): ?Journal
    {
        return $this->findOneBy(['openAlexId' => $openAlexId]);
    }

    /**
     * Recherche par nom (autocomplete) pour les filtres du back-office.
     *
     * @return list<Journal>
     */
    public function searchByName(string $q, int $limit = 20): array
    {
        return $this->createQueryBuilder('j')
            ->andWhere('LOWER(j.name) LIKE :q')
            ->setParameter('q', '%'.mb_strtolower($q).'%')
            ->orderBy('j.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
