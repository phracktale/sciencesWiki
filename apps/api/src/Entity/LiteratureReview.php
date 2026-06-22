<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LiteratureReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Revue de littérature sauvegardée par un chercheur (espace chercheur).
 * Contenu Markdown + bibliographie figés au moment de l'enregistrement.
 */
#[ORM\Entity(repositoryClass: LiteratureReviewRepository::class)]
#[ORM\Table(name: 'literature_review')]
class LiteratureReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 300)]
    private string $topic;

    /** Rubrique détectée (nœud de l'arbre le plus proche), surtitre du document. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rubric = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $contentMd;

    /** @var array<int,array<string,mixed>> Bibliographie figée. */
    #[ORM\Column(type: Types::JSON)]
    private array $sources = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<int,array<string,mixed>> $sources
     */
    public function __construct(User $user, string $topic, string $contentMd, array $sources, ?string $rubric = null)
    {
        $this->user = $user;
        $this->topic = mb_substr(trim($topic), 0, 300);
        $this->contentMd = $contentMd;
        $this->sources = $sources;
        $this->rubric = null !== $rubric && '' !== trim($rubric) ? mb_substr(trim($rubric), 0, 255) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getRubric(): ?string
    {
        return $this->rubric;
    }

    public function getContentMd(): string
    {
        return $this->contentMd;
    }

    /** @return array<int,array<string,mixed>> */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
