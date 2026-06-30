<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClassInvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Invitation d'un élève à rejoindre une {@see SchoolClass}, envoyée par e-mail.
 * Le lien porte un token opaque ; quand l'élève l'accepte (connecté), on note
 * acceptedBy/acceptedAt → il appartient dès lors à la classe (l'effectif = les
 * invitations acceptées). Inspiré du pattern contribution_token.
 */
#[ORM\Entity(repositoryClass: ClassInvitationRepository::class)]
#[ORM\Table(name: 'class_invitation')]
#[ORM\Index(name: 'idx_class_invitation_class', columns: ['school_class_id'])]
class ClassInvitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SchoolClass $schoolClass;

    /** E-mail invité (sert d'affichage ; l'élève peut s'inscrire avec un autre). */
    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    /** Élève ayant accepté (null = en attente). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    public function __construct(SchoolClass $schoolClass, string $email, string $token, int $validDays = 30)
    {
        $this->schoolClass = $schoolClass;
        $this->email = $email;
        $this->token = $token;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify("+{$validDays} days");
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchoolClass(): SchoolClass
    {
        return $this->schoolClass;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function getAcceptedBy(): ?User
    {
        return $this->acceptedBy;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function isAccepted(): bool
    {
        return null !== $this->acceptedBy;
    }

    public function accept(User $student): self
    {
        $this->acceptedBy = $student;
        $this->acceptedAt = new \DateTimeImmutable();

        return $this;
    }
}
