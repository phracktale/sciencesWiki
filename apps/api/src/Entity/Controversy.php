<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DisagreementAxis;
use App\Enum\ReviewStatus;
use App\Repository\ControversyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Désaccord détecté : cluster de claims partageant un même axe
 * (exposure_norm, outcome_norm) au sein d'un nœud, dont les directions
 * divergent (cf. docs/spec-controverses-lacunes.md §4.3 / §6.1). Membres figés
 * (ManyToMany) pour la relecture comité.
 */
#[ORM\Entity(repositoryClass: ControversyRepository::class)]
#[ORM\Table(name: 'controversy')]
#[ORM\Index(name: 'idx_controversy_node', columns: ['tree_node_id'])]
class Controversy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TreeNode $treeNode;

    #[ORM\Column(length: 255)]
    private string $exposureNorm;

    #[ORM\Column(length: 255)]
    private string $outcomeNorm;

    /** Ratio d'accord : 1 = consensus total, ~0,5 = parfaitement disputé. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $consensusScore;

    #[ORM\Column(type: Types::INTEGER)]
    private int $countPositive = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $countNegative = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $countNull = 0;

    #[ORM\Column(length: 16, enumType: DisagreementAxis::class)]
    private DisagreementAxis $disagreementAxis = DisagreementAxis::Unclear;

    /** Synthèse LLM courte, sourcée [n] (cf. système Footnote). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(length: 16, enumType: ReviewStatus::class)]
    private ReviewStatus $status = ReviewStatus::Detected;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int,Claim> */
    #[ORM\ManyToMany(targetEntity: Claim::class)]
    #[ORM\JoinTable(name: 'controversy_claim')]
    private Collection $claims;

    public function __construct(TreeNode $treeNode, string $exposureNorm, string $outcomeNorm)
    {
        $this->treeNode = $treeNode;
        $this->exposureNorm = $exposureNorm;
        $this->outcomeNorm = $outcomeNorm;
        $this->consensusScore = 1.0;
        $this->createdAt = new \DateTimeImmutable();
        $this->claims = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTreeNode(): TreeNode
    {
        return $this->treeNode;
    }

    public function getExposureNorm(): string
    {
        return $this->exposureNorm;
    }

    public function getOutcomeNorm(): string
    {
        return $this->outcomeNorm;
    }

    public function getConsensusScore(): float
    {
        return $this->consensusScore;
    }

    public function setConsensusScore(float $consensusScore): self
    {
        $this->consensusScore = $consensusScore;

        return $this;
    }

    public function getCountPositive(): int
    {
        return $this->countPositive;
    }

    public function getCountNegative(): int
    {
        return $this->countNegative;
    }

    public function getCountNull(): int
    {
        return $this->countNull;
    }

    public function setCounts(int $positive, int $negative, int $null): self
    {
        $this->countPositive = $positive;
        $this->countNegative = $negative;
        $this->countNull = $null;

        return $this;
    }

    public function getDisagreementAxis(): DisagreementAxis
    {
        return $this->disagreementAxis;
    }

    public function setDisagreementAxis(DisagreementAxis $disagreementAxis): self
    {
        $this->disagreementAxis = $disagreementAxis;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function getStatus(): ReviewStatus
    {
        return $this->status;
    }

    public function setStatus(ReviewStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int,Claim> */
    public function getClaims(): Collection
    {
        return $this->claims;
    }

    public function addClaim(Claim $claim): self
    {
        if (!$this->claims->contains($claim)) {
            $this->claims->add($claim);
        }

        return $this;
    }
}
