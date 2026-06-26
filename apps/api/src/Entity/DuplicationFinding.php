<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DuplicationType;
use App\Enum\FindingStatus;
use App\Repository\DuplicationFindingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rapprochement entre deux publications au contenu recouvrant (cf. docs/spec-plagiat.md
 * §4.2). NON DÉCISIONNEL : signal de risque, statut tranché par le comité. Distinct du
 * Deduplicator (doublon EXACT par DOI) : ici, œuvres différentes au texte recouvrant.
 */
#[ORM\Entity(repositoryClass: DuplicationFindingRepository::class)]
#[ORM\Table(name: 'duplication_finding')]
#[ORM\UniqueConstraint(name: 'uniq_pair', columns: ['source_id', 'target_id'])]
#[ORM\Index(name: 'idx_finding_status', columns: ['status'])]
class DuplicationFinding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Publication examinée (la plus récente — antériorité présumée à la cible). */
    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $source;

    /** Publication-source rapprochée (l'antériorité présumée). */
    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $target;

    #[ORM\Column(length: 16, enumType: DuplicationType::class)]
    private DuplicationType $type;

    /** Part du texte SOURCE recouvrant la cible (0..1), après filtre de légitimité. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $overlapRatio = 0.0;

    /** Jaccard verbatim maximal observé sur un couple de fragments (0..1). */
    #[ORM\Column(type: Types::FLOAT)]
    private float $maxJaccard = 0.0;

    /** Meilleure proximité sémantique (1 - distance cosinus, 0..1). */
    #[ORM\Column(type: Types::FLOAT)]
    private float $semanticSim = 0.0;

    /** true si source et cible partagent ≥1 auteur (→ auto-recouvrement). */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $sharesAuthor = false;

    /**
     * Passages mis en regard, bornés (top N) pour la lisibilité comité.
     *
     * @var list<array{srcChunkId:int, tgtChunkId:int, jaccard:float, srcText:string, tgtText:string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $passages = [];

    #[ORM\Column(length: 16, enumType: FindingStatus::class)]
    private FindingStatus $status = FindingStatus::Unreviewed;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $detectedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    public function __construct(Publication $source, Publication $target, DuplicationType $type)
    {
        $this->source = $source;
        $this->target = $target;
        $this->type = $type;
        $this->detectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): Publication
    {
        return $this->source;
    }

    public function getTarget(): Publication
    {
        return $this->target;
    }

    public function getType(): DuplicationType
    {
        return $this->type;
    }

    public function setType(DuplicationType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getOverlapRatio(): float
    {
        return $this->overlapRatio;
    }

    public function getMaxJaccard(): float
    {
        return $this->maxJaccard;
    }

    public function getSemanticSim(): float
    {
        return $this->semanticSim;
    }

    public function sharesAuthor(): bool
    {
        return $this->sharesAuthor;
    }

    /**
     * @param list<array{srcChunkId:int, tgtChunkId:int, jaccard:float, srcText:string, tgtText:string}> $passages
     */
    public function setMetrics(float $overlapRatio, float $maxJaccard, float $semanticSim, bool $sharesAuthor, array $passages): self
    {
        $this->overlapRatio = $overlapRatio;
        $this->maxJaccard = $maxJaccard;
        $this->semanticSim = $semanticSim;
        $this->sharesAuthor = $sharesAuthor;
        $this->passages = $passages;

        return $this;
    }

    /** @return list<array{srcChunkId:int, tgtChunkId:int, jaccard:float, srcText:string, tgtText:string}> */
    public function getPassages(): array
    {
        return $this->passages;
    }

    public function getStatus(): FindingStatus
    {
        return $this->status;
    }

    public function setStatus(FindingStatus $status, ?User $reviewedBy = null): self
    {
        $this->status = $status;
        $this->reviewedBy = $reviewedBy;

        return $this;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function touchDetectedAt(): self
    {
        $this->detectedAt = new \DateTimeImmutable();

        return $this;
    }
}
