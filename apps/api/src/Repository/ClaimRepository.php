<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Claim;
use App\Entity\Publication;
use App\Entity\TreeNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Claim>
 */
class ClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Claim::class);
    }

    /**
     * Claims rattachés à un nœud (contexte thématique du regroupement).
     *
     * @return list<Claim>
     */
    public function findByNode(TreeNode $node): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.treeNode = :node')
            ->setParameter('node', $node)
            ->orderBy('c.exposureNorm', 'ASC')
            ->addOrderBy('c.outcomeNorm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByNode(TreeNode $node): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.treeNode = :node')
            ->setParameter('node', $node)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasClaimsFor(Publication $publication): bool
    {
        return (bool) $this->createQueryBuilder('c')
            ->select('1')
            ->andWhere('c.publication = :pub')
            ->setParameter('pub', $publication)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Purge des claims d'une publication (idempotence de la ré-extraction —
     * cf. spec §5.3). Renvoie le nombre de lignes supprimées.
     */
    public function deleteForPublication(Publication $publication): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.publication = :pub')
            ->setParameter('pub', $publication)
            ->getQuery()
            ->execute();
    }
}
