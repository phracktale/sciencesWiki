<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publication;
use App\Enum\ProcessingStatus;
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
     * Publications ayant un DOI mais pas encore résolues OA (Unpaywall).
     *
     * @return list<Publication>
     */
    public function findNeedingOaResolution(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.doi IS NOT NULL')
            ->andWhere('p.oaResolvedAt IS NULL')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Publications sans embedding (à enrichir).
     *
     * @return list<Publication>
     */
    public function findNeedingEmbedding(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.embedding IS NULL')
            ->andWhere("p.title <> ''")
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Publications avec embedding mais pas encore placées dans l'arbre.
     *
     * @return list<Publication>
     */
    public function findNeedingPlacement(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.embedding IS NOT NULL')
            ->andWhere('p.processingStatus = :status')
            ->setParameter('status', ProcessingStatus::Normalized->value)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
