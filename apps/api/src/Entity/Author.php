<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuthorRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Auteur d'une ou plusieurs publications (cf. spec §9.1).
 */
#[ORM\Entity(repositoryClass: AuthorRepository::class)]
#[ORM\Table(name: 'author')]
#[ORM\Index(name: 'idx_author_orcid', columns: ['orcid'])]
class Author
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 512)]
    private string $name;

    #[ORM\Column(length: 32, nullable: true, unique: true)]
    private ?string $orcid = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $affiliation = null;

    public function __construct(string $name)
    {
        $this->name = $name;
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

    public function getOrcid(): ?string
    {
        return $this->orcid;
    }

    public function setOrcid(?string $orcid): self
    {
        $this->orcid = $orcid;

        return $this;
    }

    public function getAffiliation(): ?string
    {
        return $this->affiliation;
    }

    public function setAffiliation(?string $affiliation): self
    {
        $this->affiliation = $affiliation;

        return $this;
    }
}
