<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResearchGap;
use App\Entity\TreeNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResearchGap>
 */
class ResearchGapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResearchGap::class);
    }

    /**
     * Pistes d'un nœud, les plus mûres d'abord (concepts flanquants bien établis).
     *
     * @return list<ResearchGap>
     */
    public function findByNode(TreeNode $node): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.treeNode = :node')
            ->setParameter('node', $node)
            ->orderBy('g.maturityScore', 'DESC')
            ->addOrderBy('g.rarityScore', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Pistes encore à vérifier (cross-corpus §6.5), pour le GapVerifier (Phase B2).
     *
     * @return list<ResearchGap>
     */
    public function findUnverifiedByNode(TreeNode $node): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.treeNode = :node')
            ->andWhere('g.verification = :unverified')
            ->setParameter('node', $node)
            ->setParameter('unverified', \App\Enum\GapVerification::Unverified->value)
            ->getQuery()
            ->getResult();
    }

    /** Purge des pistes d'un nœud avant recomposition (idempotence de la détection). */
    public function deleteForNode(TreeNode $node): void
    {
        foreach ($this->findBy(['treeNode' => $node]) as $gap) {
            $this->getEntityManager()->remove($gap);
        }
    }
}
