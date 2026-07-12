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

    /** Empreinte d'étude (fingerprint) sérialisée. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $fingerprint = null;

    /** Plan d'analyse composite retenu. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $plan = null;

    /** Une validation humaine est requise (SPECS §14). */
    #[ORM\Column(options: ['default' => false])]
    private bool $humanReview = false;

    /** Modèle LLM utilisé (traçabilité / reproductibilité, SPECS §26). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $model = null;

    /** Demandeur (identifiant/e-mail issu du JWT) — pour la notification de fin. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $requestedBy = null;

    /** Override manuel du plan d'étude demandé (validation humaine, SPECS §13). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $designOverride = null;

    /** Relecteur ayant validé l'évaluation (identifiant/e-mail). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $validatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

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

    public function getFingerprint(): ?array
    {
        return $this->fingerprint;
    }

    public function setFingerprint(?array $fingerprint): self
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function getPlan(): ?array
    {
        return $this->plan;
    }

    public function setPlan(?array $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function isHumanReview(): bool
    {
        return $this->humanReview;
    }

    public function setHumanReview(bool $humanReview): self
    {
        $this->humanReview = $humanReview;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getRequestedBy(): ?string
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?string $requestedBy): self
    {
        $this->requestedBy = $requestedBy;

        return $this;
    }

    public function getDesignOverride(): ?string
    {
        return $this->designOverride;
    }

    public function setDesignOverride(?string $designOverride): self
    {
        $this->designOverride = $designOverride;

        return $this;
    }

    public function getValidatedBy(): ?string
    {
        return $this->validatedBy;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    /** Validation par un relecteur : fige l'évaluation comme relue. */
    public function validate(string $reviewer): self
    {
        $this->validatedBy = $reviewer;
        $this->validatedAt = new \DateTimeImmutable();
        $this->status = 'validated';
        $this->humanReview = false;

        return $this;
    }
}
