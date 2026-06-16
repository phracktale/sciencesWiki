<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Answer;
use App\Entity\Question;
use App\Enum\AnswerValidationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Answer>
 */
class AnswerRepository extends ServiceEntityRepository
{
    /** Statuts publics (cf. PublicAnswerExtension / spec §8.4). */
    private const PUBLIC_STATUSES = [
        AnswerValidationStatus::Validated,
        AnswerValidationStatus::Unreviewed,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Answer::class);
    }

    public function findOneByQuestion(Question $question): ?Answer
    {
        return $this->findOneBy(['question' => $question]);
    }

    /** Réponse publique d'une question (si déjà rédigée). */
    public function findOnePublicByQuestion(Question $question): ?Answer
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.question = :q')
            ->andWhere('a.validationStatus IN (:pub)')
            ->setParameter('q', $question)
            ->setParameter('pub', self::PUBLIC_STATUSES)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Dernières Q/R publiques (pour l'accueil).
     *
     * @return list<Answer>
     */
    public function findLatestPublic(int $limit = 10): array
    {
        /** @var list<Answer> $r */
        $r = $this->createQueryBuilder('a')
            ->andWhere('a.validationStatus IN (:pub)')
            ->setParameter('pub', self::PUBLIC_STATUSES)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $r;
    }
}
