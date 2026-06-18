<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PublisherRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Éditeur scientifique (host_organization OpenAlex) : Elsevier, Springer, etc.
 * Alimenté au fil de la moisson à partir des revues rencontrées.
 */
#[ORM\Entity(repositoryClass: PublisherRepository::class)]
#[ORM\Table(name: 'publisher')]
#[ORM\Index(name: 'idx_publisher_openalex', columns: ['openalex_id'])]
class Publisher
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['publisher:read'])]
    private ?int $id = null;

    #[ORM\Column(name: 'openalex_id', length: 64, unique: true, nullable: true)]
    #[Groups(['publisher:read'])]
    private ?string $openAlexId = null;

    #[ORM\Column(length: 512)]
    #[Groups(['publisher:read'])]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['publisher:read'])]
    private ?string $homepageUrl = null;

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

    public function getHomepageUrl(): ?string
    {
        return $this->homepageUrl;
    }

    public function setHomepageUrl(?string $homepageUrl): self
    {
        $this->homepageUrl = $homepageUrl;

        return $this;
    }
}
