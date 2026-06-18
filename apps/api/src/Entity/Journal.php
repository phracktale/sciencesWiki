<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JournalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Revue / source de publication (journal, dépôt, actes…), telle que décrite par
 * `primary_location.source` chez OpenAlex. Alimentée au fil de la moisson et
 * rattachée à son éditeur ({@see Publisher}).
 */
#[ORM\Entity(repositoryClass: JournalRepository::class)]
#[ORM\Table(name: 'journal')]
#[ORM\Index(name: 'idx_journal_openalex', columns: ['openalex_id'])]
#[ORM\Index(name: 'idx_journal_name', columns: ['name'])]
class Journal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['journal:read'])]
    private ?int $id = null;

    #[ORM\Column(name: 'openalex_id', length: 64, unique: true, nullable: true)]
    #[Groups(['journal:read'])]
    private ?string $openAlexId = null;

    #[ORM\Column(length: 512)]
    #[Groups(['journal:read'])]
    private string $name;

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['journal:read'])]
    private ?string $issnL = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['journal:read'])]
    private ?string $type = null;

    #[ORM\Column]
    #[Groups(['journal:read'])]
    private bool $isOa = false;

    #[ORM\Column]
    #[Groups(['journal:read'])]
    private bool $isInDoaj = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['journal:read'])]
    private ?string $homepageUrl = null;

    #[ORM\ManyToOne(targetEntity: Publisher::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['journal:read'])]
    private ?Publisher $publisher = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOpenAlexId(): ?string
    {
        return $this->openAlexId;
    }

    public function setOpenAlexId(?string $openAlexId): self
    {
        $this->openAlexId = $openAlexId;

        return $this;
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

    public function getIssnL(): ?string
    {
        return $this->issnL;
    }

    public function setIssnL(?string $issnL): self
    {
        $this->issnL = $issnL;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function isOa(): bool
    {
        return $this->isOa;
    }

    public function setIsOa(bool $isOa): self
    {
        $this->isOa = $isOa;

        return $this;
    }

    public function isInDoaj(): bool
    {
        return $this->isInDoaj;
    }

    public function setIsInDoaj(bool $isInDoaj): self
    {
        $this->isInDoaj = $isInDoaj;

        return $this;
    }

    public function getHomepageUrl(): ?string
    {
        return $this->homepageUrl;
    }

    public function setHomepageUrl(?string $homepageUrl): self
    {
        $this->homepageUrl = $homepageUrl;

        return $this;
    }

    public function getPublisher(): ?Publisher
    {
        return $this->publisher;
    }

    public function setPublisher(?Publisher $publisher): self
    {
        $this->publisher = $publisher;

        return $this;
    }
}
