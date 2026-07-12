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
     * Dernières Q/R publiques (accueil / page « toutes les questions »).
     * Si $search est fourni, filtre sur le texte de la question et son titre.
     *
     * @return list<Answer>
     */
    public function findLatestPublic(int $limit = 10, int $offset = 0, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.validationStatus IN (:pub)')
            ->setParameter('pub', self::PUBLIC_STATUSES)
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit);

        if (null !== $search && '' !== trim($search)) {
            // questionText n'est pas mappé (dérivé de question->getText()) : on filtre
            // sur les champs RÉELS de la question jointe (texte complet + titre court).
            $qb->join('a.question', 'q')
                ->andWhere('LOWER(q.text) LIKE :s OR LOWER(q.title) LIKE :s')
                ->setParameter('s', '%'.mb_strtolower(trim($search)).'%');
        }

        /** @var list<Answer> $r */
        $r = $qb->getQuery()->getResult();

        return $r;
    }
}
