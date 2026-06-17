<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lien ordonné entre une publication et un auteur (le rang sert à identifier
 * le premier auteur — cf. spec §8.6 « auteur principal »).
 */
#[ORM\Entity]
#[ORM\Table(name: 'authorship')]
#[ORM\UniqueConstraint(name: 'uniq_authorship', columns: ['publication_id', 'author_id'])]
class Authorship
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Publication::class, inversedBy: 'authorships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Publication $publication = null;

    #[ORM\ManyToOne(targetEntity: Author::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private Author $author;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    public function __construct(Author $author, int $position = 0)
    {
        $this->author = $author;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): ?Publication
    {
        return $this->publication;
    }

    public function setPublication(?Publication $publication): self
    {
        $this->publication = $publication;

        return $this;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }
}
