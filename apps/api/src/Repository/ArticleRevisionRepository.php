<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArticleRevision;
use App\Entity\TreeNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArticleRevision>
 */
final class ArticleRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArticleRevision::class);
    }

    /** @return list<ArticleRevision> historique du plus récent au plus ancien */
    public function forNode(TreeNode $node): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.treeNode = :n')->setParameter('n', $node)
            ->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->getQuery()->getResult();
    }
}
