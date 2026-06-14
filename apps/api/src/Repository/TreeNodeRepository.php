<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TreeNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pgvector\Vector;

/**
 * @extends ServiceEntityRepository<TreeNode>
 */
class TreeNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TreeNode::class);
    }

    public function findOneByConceptId(string $conceptId): ?TreeNode
    {
        return $this->findOneBy(['openalexConceptId' => $conceptId]);
    }

    /**
     * Nœuds les plus proches d'un embedding, par distance cosinus pgvector.
     *
     * @param list<float> $embedding
     *
     * @return list<array{node:TreeNode,distance:float}>
     */
    public function nearestTo(array $embedding, int $k): array
    {
        $literal = (string) new Vector($embedding);
        $k = max(1, $k);

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            \sprintf(
                'SELECT id, embedding <=> CAST(:vec AS vector) AS distance
                 FROM tree_node
                 WHERE embedding IS NOT NULL
                 ORDER BY distance ASC
                 LIMIT %d',
                $k,
            ),
            ['vec' => $literal],
        )->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $node = $this->find((int) $row['id']);
            if (null !== $node) {
                $result[] = ['node' => $node, 'distance' => (float) $row['distance']];
            }
        }

        return $result;
    }

    public function countWithEmbedding(): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.embedding IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
