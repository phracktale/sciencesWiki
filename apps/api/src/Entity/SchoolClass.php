<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SchoolClassRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une classe pédagogique : un enseignant (ROLE_TEACHER) la crée et y relie ses
 * élèves via des invitations par e-mail ({@see ClassInvitation}). L'effectif réel
 * = les invitations acceptées (pas de table d'adhésion séparée).
 */
#[ORM\Entity(repositoryClass: SchoolClassRepository::class)]
#[ORM\Table(name: 'school_class')]
class SchoolClass
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name;

    /** Enseignant propriétaire (ROLE_TEACHER). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $teacher;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $teacher, string $name)
    {
        $this->teacher = $teacher;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
