<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorpusSubmission;
use App\Entity\Publication;
use App\Entity\User;
use App\Enum\SubmissionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorpusSubmission>
 */
final class CorpusSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorpusSubmission::class);
    }

    public function findForPublication(Publication $publication): ?CorpusSubmission
    {
        return $this->findOneBy(['publication' => $publication]);
    }

    /**
     * File de revue comité : propositions en attente, plus anciennes d'abord.
     *
     * @return list<CorpusSubmission>
     */
    public function findPending(int $limit = 100): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :st')
            ->setParameter('st', SubmissionStatus::Pending)
            ->orderBy('s.submittedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Études déposées par un utilisateur (son espace « mes études »), récentes d'abord.
     *
     * @return list<CorpusSubmission>
     */
    public function findByUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.submittedBy = :u')
            ->setParameter('u', $user)
            ->orderBy('s.submittedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.status = :st')
            ->setParameter('st', SubmissionStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
