<?php

declare(strict_types=1);

namespace Analyses\Entity;

use Analyses\Repository\AssessmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Résultat canonique d'une évaluation (cf. docs/Modules/analyses/SPECS.md §19).
 * Table préfixée `analys_` dans la base SciencesWiki partagée. Aucune FK vers le
 * cœur : la publication est référencée par son identifiant (document_ref).
 */
#[ORM\Entity(repositoryClass: AssessmentRepository::class)]
#[ORM\Table(name: 'analys_assessment')]
#[ORM\Index(name: 'idx_analys_assessment_doc', columns: ['document_ref'])]
class Assessment
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $id;

    /** Référence de la publication analysée (id/DOI côté corpus SW). */
    #[ORM\Column(length: 255)]
    private string $documentRef;

    /** Plan d'étude principal détecté (code d'ontologie). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $primaryDesign = null;

    /** Statut : draft | routed | running | completed | human_review_required. */
    #[ORM\Column(length: 32)]
    private string $status = 'draft';

    /** Confiance de routage [0..1]. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $routingConfidence = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $documentRef)
    {
        $this->id = new Ulid();
        $this->documentRef = $documentRef;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getDocumentRef(): string
    {
        return $this->documentRef;
    }

    public function getPrimaryDesign(): ?string
    {
        return $this->primaryDesign;
    }

    public function setPrimaryDesign(?string $primaryDesign): self
    {
        $this->primaryDesign = $primaryDesign;

        return $this;
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

    public function getRoutingConfidence(): ?float
    {
        return $this->routingConfidence;
    }

    public function setRoutingConfidence(?float $routingConfidence): self
    {
        $this->routingConfidence = $routingConfidence;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
