<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Note de bas de page reliant une affirmation du bloc académique à une
 * publication source (cf. spec §8.3/§9.3). Garantit le sourcing par DOI.
 */
#[ORM\Entity]
#[ORM\Table(name: 'footnote')]
class Footnote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnswerRevision::class, inversedBy: 'footnotes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AnswerRevision $answerRevision = null;

    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Publication $publication;

    /** Numéro de la note (¹²³). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $marker;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $doi = null;

    public function __construct(Publication $publication, int $marker)
    {
        $this->publication = $publication;
        $this->marker = $marker;
        $this->doi = $publication->getDoi();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnswerRevision(): ?AnswerRevision
    {
        return $this->answerRevision;
    }

    public function setAnswerRevision(?AnswerRevision $answerRevision): self
    {
        $this->answerRevision = $answerRevision;

        return $this;
    }

    public function getPublication(): Publication
    {
        return $this->publication;
    }

    public function getMarker(): int
    {
        return $this->marker;
    }

    public function getDoi(): ?string
    {
        return $this->doi;
    }
}
