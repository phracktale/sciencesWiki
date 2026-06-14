<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publication;
use App\Harvester\Pipeline\PublicationLookup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Publication>
 */
class PublicationRepository extends ServiceEntityRepository implements PublicationLookup
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Publication::class);
    }

    public function findOneByDoi(string $doi): ?Publication
    {
        return $this->findOneBy(['doi' => $doi]);
    }

    /**
     * Recherche par identifiant externe (ex. openalex/arxiv) stocké dans le JSON
     * `external_ids`. Utilise l'opérateur JSON de PostgreSQL.
     */
    public function findOneByExternalId(string $key, string $value): ?Publication
    {
        $sql = 'SELECT id FROM publication WHERE external_ids ->> :key = :value LIMIT 1';
        $id = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['key' => $key, 'value' => $value])
            ->fetchOne();

        return false === $id ? null : $this->find((int) $id);
    }
}
