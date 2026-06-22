<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnswerVote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnswerVote>
 */
class AnswerVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnswerVote::class);
    }

    public function findOneByAnswerAndKey(int $answerId, string $voterKey): ?AnswerVote
    {
        return $this->findOneBy(['answer' => $answerId, 'voterKey' => $voterKey]);
    }

    /**
     * Agrégats pondérés ET bruts par réponse.
     *
     * @param list<int> $answerIds
     *
     * @return array<int,array{okWeight:int,notOkWeight:int,okCount:int,notOkCount:int}>
     */
    public function tallyFor(array $answerIds): array
    {
        if ([] === $answerIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT answer_id,
                    COALESCE(SUM(CASE WHEN value > 0 THEN weight ELSE 0 END), 0) AS ok_weight,
                    COALESCE(SUM(CASE WHEN value < 0 THEN weight ELSE 0 END), 0) AS notok_weight,
                    COUNT(*) FILTER (WHERE value > 0) AS ok_count,
                    COUNT(*) FILTER (WHERE value < 0) AS notok_count
             FROM answer_vote
             WHERE answer_id IN (:ids)
             GROUP BY answer_id",
            ['ids' => $answerIds],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['answer_id']] = [
                'okWeight' => (int) $r['ok_weight'],
                'notOkWeight' => (int) $r['notok_weight'],
                'okCount' => (int) $r['ok_count'],
                'notOkCount' => (int) $r['notok_count'],
            ];
        }

        return $out;
    }

    /**
     * Vote courant d'un votant pour un ensemble de réponses.
     *
     * @param list<int> $answerIds
     *
     * @return array<int,int> answerId => +1 / -1
     */
    public function myVotes(array $answerIds, string $voterKey): array
    {
        if ([] === $answerIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT answer_id, value FROM answer_vote WHERE voter_key = :k AND answer_id IN (:ids)',
            ['k' => $voterKey, 'ids' => $answerIds],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['answer_id']] = (int) $r['value'];
        }

        return $out;
    }
}
