<?php

declare(strict_types=1);

namespace Analyses\Repository;

use Analyses\Entity\AssessmentCriterion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<AssessmentCriterion>
 */
class AssessmentCriterionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentCriterion::class);
    }

    /** @return list<AssessmentCriterion> */
    public function findForAssessment(Ulid $assessmentId): array
    {
        return $this->findBy(['assessmentId' => $assessmentId], ['criterionId' => 'ASC']);
    }
}
