<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoadmapProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Proposition de roadmap soumise par un visiteur (page /roadmap). Stockée pour
 * gestion en back-office ET notifiée par e-mail à l'équipe.
 */
#[ORM\Entity(repositoryClass: RoadmapProposalRepository::class)]
#[ORM\Table(name: 'roadmap_proposal')]
class RoadmapProposal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /** 'new' | 'planned' | 'declined' | 'done' — géré en back-office. */
    #[ORM\Column(length: 16, options: ['default' => 'new'])]
    private string $status = 'new';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $message)
    {
        $this->message = $message;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = null !== $name ? mb_substr($name, 0, 120) : null;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = null !== $email ? mb_substr($email, 0, 180) : null;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
