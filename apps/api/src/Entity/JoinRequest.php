<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JoinRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande pour « Nous rejoindre » : comité scientifique, auteur ou rédacteur.
 * Stockée pour traitement en back-office (promotion de compte + e-mail).
 */
#[ORM\Entity(repositoryClass: JoinRequestRepository::class)]
#[ORM\Table(name: 'join_request')]
#[ORM\Index(name: 'idx_join_status', columns: ['status'])]
class JoinRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** committee | author | editor */
    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(length: 120)]
    private string $lastName;

    #[ORM\Column(length: 120)]
    private string $firstName;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    /** Comité : chercheur | vulgarisateur. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $profile = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $orcid = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $profession = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** pending | handled */
    #[ORM\Column(length: 16)]
    private string $status = 'pending';

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $type, string $lastName, string $firstName)
    {
        $this->type = $type;
        $this->lastName = $lastName;
        $this->firstName = $firstName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $v): self
    {
        $this->email = $v;

        return $this;
    }

    public function getProfile(): ?string
    {
        return $this->profile;
    }

    public function setProfile(?string $v): self
    {
        $this->profile = $v;

        return $this;
    }

    public function getOrcid(): ?string
    {
        return $this->orcid;
    }

    public function setOrcid(?string $v): self
    {
        $this->orcid = $v;

        return $this;
    }

    public function getProfession(): ?string
    {
        return $this->profession;
    }

    public function setProfession(?string $v): self
    {
        $this->profession = $v;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $v): self
    {
        $this->message = $v;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $v): self
    {
        $this->status = $v;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $v): self
    {
        $this->ip = $v;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
