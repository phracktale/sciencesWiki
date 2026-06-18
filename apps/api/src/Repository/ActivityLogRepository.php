<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActivityLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /**
     * Page d'historique, filtrable par catégorie.
     *
     * @return array{items: list<ActivityLog>, total: int, page: int, pages: int, category: string}
     */
    public function page(string $category, int $page, int $perPage = 50): array
    {
        $qb = $this->createQueryBuilder('a')->orderBy('a.occurredAt', 'DESC');
        if ('' !== $category) {
            $qb->where('a.category = :c')->setParameter('c', $category);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $perPage),
            'category' => $category,
        ];
    }

    /** @return list<string> catégories distinctes présentes dans le journal */
    public function categories(): array
    {
        $rows = $this->createQueryBuilder('a')->select('DISTINCT a.category')->orderBy('a.category', 'ASC')->getQuery()->getScalarResult();

        return array_map(static fn (array $r): string => (string) $r['category'], $rows);
    }
}
