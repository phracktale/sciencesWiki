<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NewsletterSignupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Inscription « tenez-moi au courant » depuis une page-cible (journalistes,
 * grand public…). Légère : e-mail + cible. Gérée en back-office.
 */
#[ORM\Entity(repositoryClass: NewsletterSignupRepository::class)]
#[ORM\Table(name: 'newsletter_signup')]
#[ORM\Index(name: 'idx_newsletter_audience', columns: ['audience'])]
class NewsletterSignup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    /** Cible d'origine : 'journalists' | 'public' | 'researchers' | 'teachers' | 'other'. */
    #[ORM\Column(length: 32)]
    private string $audience = 'other';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $audience = 'other')
    {
        $this->email = mb_substr($email, 0, 180);
        $this->audience = mb_substr($audience, 0, 32);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
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

    public function getAudience(): string
    {
        return $this->audience;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
