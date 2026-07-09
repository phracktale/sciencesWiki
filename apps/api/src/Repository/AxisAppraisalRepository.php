<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AxisAppraisal;
use App\Entity\Publication;
use App\Entity\TreeNode;
use App\Enum\AxisApplicability;
use App\Enum\ReviewStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AxisAppraisal>
 */
class AxisAppraisalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AxisAppraisal::class);
    }

    public function findForPublication(Publication $publication): ?AxisAppraisal
    {
        return $this->findOneBy(['publication' => $publication]);
    }

    public function hasAppraisalFor(Publication $publication): bool
    {
        return (bool) $this->createQueryBuilder('a')
            ->select('1')
            ->andWhere('a.publication = :pub')
            ->setParameter('pub', $publication)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Purge l'évaluation d'une publication (idempotence de la ré-évaluation).
     * Renvoie le nombre de lignes supprimées.
     */
    public function deleteForPublication(Publication $publication): int
    {
        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->andWhere('a.publication = :pub')
            ->setParameter('pub', $publication)
            ->getQuery()
            ->execute();
    }

    /**
     * Évaluations d'un nœud (back-office comité), les plus récentes d'abord.
     *
     * @return list<AxisAppraisal>
     */
    public function findByNode(TreeNode $node): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.treeNode = :node')
            ->setParameter('node', $node)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * File d'examen comité : évaluations applicables non encore tranchées
     * (Detected / UnderReview), les plus récentes d'abord. Les « non applicable »
     * (autre design) sont exclues — rien à valider.
     *
     * @return list<AxisAppraisal>
     */
    public function pending(int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status IN (:open)')
            ->andWhere('a.applicability != :na')
            ->setParameter('open', [ReviewStatus::Detected->value, ReviewStatus::UnderReview->value])
            ->setParameter('na', AxisApplicability::NotApplicable->value)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Bandes de fiabilité AXIS pour un LOT de publications (badge dans la liste de
     * recherche) — une seule requête. Exclut les évaluations écartées par le comité
     * (Dismissed) et celles sans bande (non applicable / non évaluées).
     *
     * @param list<int> $ids
     *
     * @return array<int,string> publicationId → bande (high|moderate|low|insufficient)
     */
    public function bandsFor(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.publication) AS pid', 'a.reliabilityBand AS band')
            ->andWhere('a.publication IN (:ids)')
            ->andWhere('a.status != :dismissed')
            ->andWhere('a.reliabilityBand IS NOT NULL')
            ->setParameter('ids', $ids)
            ->setParameter('dismissed', ReviewStatus::Dismissed->value)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['pid']] = (string) $row['band'];
        }

        return $map;
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.status IN (:open)')
            ->andWhere('a.applicability != :na')
            ->setParameter('open', [ReviewStatus::Detected->value, ReviewStatus::UnderReview->value])
            ->setParameter('na', AxisApplicability::NotApplicable->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
