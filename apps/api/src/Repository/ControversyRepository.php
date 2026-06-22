<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Controversy;
use App\Entity\TreeNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Controversy>
 */
class ControversyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Controversy::class);
    }

    /**
     * Controverses d'un nœud, les plus disputées d'abord (consensus croissant).
     *
     * @return list<Controversy>
     */
    public function findByNode(TreeNode $node): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.treeNode = :node')
            ->setParameter('node', $node)
            ->orderBy('c.consensusScore', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Purge des controverses d'un nœud avant recomposition (idempotence de la
     * détection). Les liens controversy_claim partent en cascade (ORM).
     */
    public function deleteForNode(TreeNode $node): void
    {
        foreach ($this->findBy(['treeNode' => $node]) as $controversy) {
            $this->getEntityManager()->remove($controversy);
        }
    }
}
