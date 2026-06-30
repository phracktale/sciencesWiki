<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassInvitation;
use App\Entity\SchoolClass;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassInvitation>
 */
final class ClassInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassInvitation::class);
    }

    public function findOneByToken(string $token): ?ClassInvitation
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** @return list<ClassInvitation> toutes les invitations d'une classe (effectif + en attente) */
    public function findByClass(SchoolClass $class): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.schoolClass = :c')->setParameter('c', $class)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** Invitation en attente déjà émise pour ce binôme (classe, e-mail) — anti-doublon. */
    public function findPendingByClassAndEmail(SchoolClass $class, string $email): ?ClassInvitation
    {
        return $this->createQueryBuilder('i')
            ->where('i.schoolClass = :c')->setParameter('c', $class)
            ->andWhere('LOWER(i.email) = :e')->setParameter('e', mb_strtolower($email))
            ->andWhere('i.acceptedBy IS NULL')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<ClassInvitation> invitations acceptées par cet élève (= ses classes) */
    public function findAcceptedByStudent(User $student): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.acceptedBy = :s')->setParameter('s', $student)
            ->orderBy('i.acceptedAt', 'DESC')
            ->getQuery()->getResult();
    }
}
