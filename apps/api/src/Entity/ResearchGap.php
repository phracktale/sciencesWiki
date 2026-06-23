<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClaimDirection;
use App\Enum\GapType;
use App\Enum\GapVerification;
use App\Enum\ReviewStatus;
use App\Repository\ResearchGapRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Piste inexplorée détectée (chaînon manquant Swanson, case creuse, ou lacune
 * auto-déclarée) — cf. docs/spec-controverses-lacunes.md §4.4 / §6.2–§6.5.
 *
 * Non décisionnelle : validée par le comité (status). Les champs de vérification
 * croisée sont renseignés par GapVerifier (§6.5).
 */
#[ORM\Entity(repositoryClass: ResearchGapRepository::class)]
#[ORM\Table(name: 'research_gap')]
#[ORM\Index(name: 'idx_research_gap_node', columns: ['tree_node_id'])]
class ResearchGap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: GapType::class)]
    private GapType $type;

    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TreeNode $treeNode = null;

    /** Concept A (exposition / variable) — libellé normalisé. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $conceptA = null;

    /** Concept B (chaînon intermédiaire Swanson), si pertinent. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $conceptB = null;

    /** Concept C (résultat / variable cible). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $conceptC = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** Robustesse des concepts flanquants (1 = chacun bien établi). */
    #[ORM\Column(type: Types::FLOAT)]
    private float $maturityScore = 0.0;

    /** Rareté de la piste (1 = jamais testée directement). */
    #[ORM\Column(type: Types::FLOAT)]
    private float $rarityScore = 0.0;

    /** Nombre de signaux à l'appui (ex. nb d'auteurs réclamant la piste). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $evidenceCount = 0;

    /**
     * Publications à l'appui (signal citable).
     *
     * @var list<int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $supportingPublicationIds = [];

    #[ORM\Column(length: 16, enumType: ReviewStatus::class)]
    private ReviewStatus $status = ReviewStatus::Detected;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // --- Vérification croisée (§6.5, renseignée par GapVerifier en Phase B2) ---

    #[ORM\Column(length: 16, enumType: GapVerification::class)]
    private GapVerification $verification = GapVerification::Unverified;

    /** Signe plausible déduit de la chaîne ABC (intuition de l'IA). */
    #[ORM\Column(length: 16, nullable: true, enumType: ClaimDirection::class)]
    private ?ClaimDirection $expectedDirection = null;

    /** Signe réellement trouvé par les études externes. */
    #[ORM\Column(length: 16, nullable: true, enumType: ClaimDirection::class)]
    private ?ClaimDirection $observedDirection = null;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $corroboratingPublicationIds = [];

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $contestingPublicationIds = [];

    /** Encart généré : en quoi le réel diverge de l'intuition, et avec quel protocole. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $divergenceNote = null;

    /** Portée de la vérification : 'corpus' | 'corpus+openalex'. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $verifiedScope = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    public function __construct(GapType $type, string $description)
    {
        $this->type = $type;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): GapType
    {
        return $this->type;
    }

    public function getTreeNode(): ?TreeNode
    {
        return $this->treeNode;
    }

    public function setTreeNode(?TreeNode $treeNode): self
    {
        $this->treeNode = $treeNode;

        return $this;
    }

    public function getConceptA(): ?string
    {
        return $this->conceptA;
    }

    public function setConceptA(?string $c): self
    {
        $this->conceptA = null !== $c ? mb_substr($c, 0, 255) : null;

        return $this;
    }

    public function getConceptB(): ?string
    {
        return $this->conceptB;
    }

    public function setConceptB(?string $c): self
    {
        $this->conceptB = null !== $c ? mb_substr($c, 0, 255) : null;

        return $this;
    }

    public function getConceptC(): ?string
    {
        return $this->conceptC;
    }

    public function setConceptC(?string $c): self
    {
        $this->conceptC = null !== $c ? mb_substr($c, 0, 255) : null;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getMaturityScore(): float
    {
        return $this->maturityScore;
    }

    public function setMaturityScore(float $s): self
    {
        $this->maturityScore = $s;

        return $this;
    }

    public function getRarityScore(): float
    {
        return $this->rarityScore;
    }

    public function setRarityScore(float $s): self
    {
        $this->rarityScore = $s;

        return $this;
    }

    public function getEvidenceCount(): int
    {
        return $this->evidenceCount;
    }

    public function setEvidenceCount(int $n): self
    {
        $this->evidenceCount = $n;

        return $this;
    }

    /** @return list<int> */
    public function getSupportingPublicationIds(): array
    {
        return $this->supportingPublicationIds;
    }

    /** @param list<int> $ids */
    public function setSupportingPublicationIds(array $ids): self
    {
        $this->supportingPublicationIds = array_values(array_unique(array_map('intval', $ids)));

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

    public function getVerification(): GapVerification
    {
        return $this->verification;
    }

    public function setVerification(GapVerification $v): self
    {
        $this->verification = $v;

        return $this;
    }

    public function getExpectedDirection(): ?ClaimDirection
    {
        return $this->expectedDirection;
    }

    public function setExpectedDirection(?ClaimDirection $d): self
    {
        $this->expectedDirection = $d;

        return $this;
    }

    public function getObservedDirection(): ?ClaimDirection
    {
        return $this->observedDirection;
    }

    public function setObservedDirection(?ClaimDirection $d): self
    {
        $this->observedDirection = $d;

        return $this;
    }

    /** @return list<int> */
    public function getCorroboratingPublicationIds(): array
    {
        return $this->corroboratingPublicationIds;
    }

    /** @param list<int> $ids */
    public function setCorroboratingPublicationIds(array $ids): self
    {
        $this->corroboratingPublicationIds = array_values(array_unique(array_map('intval', $ids)));

        return $this;
    }

    /** @return list<int> */
    public function getContestingPublicationIds(): array
    {
        return $this->contestingPublicationIds;
    }

    /** @param list<int> $ids */
    public function setContestingPublicationIds(array $ids): self
    {
        $this->contestingPublicationIds = array_values(array_unique(array_map('intval', $ids)));

        return $this;
    }

    public function getDivergenceNote(): ?string
    {
        return $this->divergenceNote;
    }

    public function setDivergenceNote(?string $note): self
    {
        $this->divergenceNote = $note;

        return $this;
    }

    public function getVerifiedScope(): ?string
    {
        return $this->verifiedScope;
    }

    public function setVerifiedScope(?string $scope): self
    {
        $this->verifiedScope = $scope;

        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $at): self
    {
        $this->verifiedAt = $at;

        return $this;
    }
}
