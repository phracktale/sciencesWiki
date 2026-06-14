<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publication;
use App\Enum\ProcessingStatus;
use App\Harvester\Pipeline\PublicationLookup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pgvector\Vector;

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
     * Recherche sémantique : publications les plus proches d'un embedding
     * (distance cosinus pgvector).
     *
     * @param list<float> $embedding
     *
     * @return list<array{publication:Publication,distance:float}>
     */
    public function nearestTo(array $embedding, int $k): array
    {
        $literal = (string) new Vector($embedding);
        $k = max(1, $k);

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            \sprintf(
                'SELECT id, embedding <=> CAST(:vec AS vector) AS distance
                 FROM publication
                 WHERE embedding IS NOT NULL
                 ORDER BY distance ASC
                 LIMIT %d',
                $k,
            ),
            ['vec' => $literal],
        )->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $publication = $this->find((int) $row['id']);
            if (null !== $publication) {
                $result[] = ['publication' => $publication, 'distance' => (float) $row['distance']];
            }
        }

        return $result;
    }

    /**
     * Recherche plein-texte simple (titre + résumé).
     *
     * @return list<Publication>
     */
    public function textSearch(string $query, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('LOWER(p.title) LIKE :q OR LOWER(p.abstract) LIKE :q')
            ->setParameter('q', '%'.mb_strtolower($query).'%')
            ->orderBy('p.publicationDate', 'DESC')
            ->setMaxResults(max(1, $limit))
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
