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
}
