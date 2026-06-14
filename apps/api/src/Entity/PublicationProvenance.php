<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Trace de provenance : quelle source a fourni quelle publication, avec la
 * licence constatée (audit ; une même publication peut venir de plusieurs
 * sources — cf. spec §9.1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'publication_provenance')]
#[ORM\UniqueConstraint(name: 'uniq_provenance', columns: ['publication_id', 'source_id'])]
class PublicationProvenance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Publication::class, inversedBy: 'provenances')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Publication $publication = null;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Source $source;

    #[ORM\Column(length: 255)]
    private string $idInSource;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $observedLicense = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $fetchedAt;

    public function __construct(Source $source, string $idInSource, ?string $observedLicense = null)
    {
        $this->source = $source;
        $this->idInSource = $idInSource;
        $this->observedLicense = $observedLicense;
        $this->fetchedAt = new \DateTimeImmutable();
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

    public function getSource(): Source
    {
        return $this->source;
    }

    public function getIdInSource(): string
    {
        return $this->idInSource;
    }

    public function getObservedLicense(): ?string
    {
        return $this->observedLicense;
    }

    public function setObservedLicense(?string $observedLicense): self
    {
        $this->observedLicense = $observedLicense;

        return $this;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }
}
