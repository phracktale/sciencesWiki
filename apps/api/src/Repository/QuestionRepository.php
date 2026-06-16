<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Question;
use App\Entity\TreeNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Question>
 */
class QuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Question::class);
    }

    public function findOneByNodeAndText(TreeNode $node, string $text): ?Question
    {
        return $this->findOneBy(['treeNode' => $node, 'text' => $text]);
    }

    /** Nombre de questions posées par une IP depuis une date (anti-abus). */
    public function countRecentByIp(string $ip, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->andWhere('q.askerIp = :ip')
            ->andWhere('q.createdAt >= :since')
            ->setParameter('ip', $ip)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
