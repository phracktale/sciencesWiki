<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AxisApplicability;
use App\Enum\ReviewStatus;
use App\Repository\AxisAppraisalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Évaluation critique d'une publication par la grille AXIS (études transversales,
 * cf. docs/spec-axis-articles.md). UNE évaluation par publication (FK unique).
 *
 * NON DÉCISIONNELLE : signal de qualité méthodologique généré par LLM, ancré sur
 * des citations verbatim, tranché par le comité ({@see ReviewStatus}) — rien n'est
 * montré au public tant que non « Confirmed », comme les controverses et les
 * rapprochements de plagiat.
 */
#[ORM\Entity(repositoryClass: AxisAppraisalRepository::class)]
#[ORM\Table(name: 'axis_appraisal')]
#[ORM\UniqueConstraint(name: 'uniq_axis_publication', columns: ['publication_id'])]
#[ORM\Index(name: 'idx_axis_status', columns: ['status'])]
class AxisAppraisal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $publication;

    /** Contexte thématique (placement validé), pour scoper l'analyse par nœud. */
    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TreeNode $treeNode = null;

    #[ORM\Column(length: 16, enumType: AxisApplicability::class)]
    private AxisApplicability $applicability = AxisApplicability::Uncertain;

    /** Design d'étude détecté par le LLM (cross-sectional, rct, cohort…), pour audit. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $studyDesign = null;

    /**
     * Réponses aux 20 items : { "q1": "yes", … } (vide si non applicable).
     *
     * @var array<string,string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    /**
     * Citation verbatim justifiant chaque réponse défavorable/critique :
     * { "q3": "phrase exacte…" } (traçabilité, garde-fou anti-hallucination).
     *
     * @var array<string,string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $justifications = [];

    /** Nombre d'items répondus FAVORABLEMENT (indicatif, cf. §2). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $favorableCount = 0;

    /** Nombre d'items réellement évaluables (réponse ≠ « indéterminé »). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $assessableCount = 0;

    /** Bande indicative de fiabilité : high|moderate|low|insufficient (PAS un score). */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $reliabilityBand = null;

    /** Étendue du texte source exploité : abstract | abstract+fulltext. */
    #[ORM\Column(length: 24)]
    private string $sourceScope = 'abstract';

    /** Synthèse courte (forces / faiblesses méthodologiques), générée LLM. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /** Modèle LLM figé à l'évaluation (immutabilité de la provenance). */
    #[ORM\Column(length: 128)]
    private string $appraisalModel;

    #[ORM\Column(length: 16, enumType: ReviewStatus::class)]
    private ReviewStatus $status = ReviewStatus::Detected;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Durée de génération LLM (ms) et total de tokens consommés (provenance / PDF). */
    #[ORM\Column(nullable: true)]
    private ?int $generationMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $tokens = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    public function __construct(Publication $publication, string $appraisalModel)
    {
        $this->publication = $publication;
        $this->appraisalModel = $appraisalModel;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): Publication
    {
        return $this->publication;
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

    public function getApplicability(): AxisApplicability
    {
        return $this->applicability;
    }

    public function setApplicability(AxisApplicability $applicability): self
    {
        $this->applicability = $applicability;

        return $this;
    }

    public function getStudyDesign(): ?string
    {
        return $this->studyDesign;
    }

    public function setStudyDesign(?string $studyDesign): self
    {
        $this->studyDesign = null !== $studyDesign ? mb_substr($studyDesign, 0, 64) : null;

        return $this;
    }

    /** @return array<string,string> */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    /** @param array<string,string> $answers */
    public function setAnswers(array $answers): self
    {
        $this->answers = $answers;

        return $this;
    }

    /** @return array<string,string> */
    public function getJustifications(): array
    {
        return $this->justifications;
    }

    /** @param array<string,string> $justifications */
    public function setJustifications(array $justifications): self
    {
        $this->justifications = $justifications;

        return $this;
    }

    public function getFavorableCount(): int
    {
        return $this->favorableCount;
    }

    public function getAssessableCount(): int
    {
        return $this->assessableCount;
    }

    public function getReliabilityBand(): ?string
    {
        return $this->reliabilityBand;
    }

    public function setScoring(int $favorableCount, int $assessableCount, ?string $reliabilityBand): self
    {
        $this->favorableCount = $favorableCount;
        $this->assessableCount = $assessableCount;
        $this->reliabilityBand = $reliabilityBand;

        return $this;
    }

    public function getGenerationMs(): ?int
    {
        return $this->generationMs;
    }

    public function getTokens(): ?int
    {
        return $this->tokens;
    }

    public function setGeneration(?int $generationMs, ?int $tokens): self
    {
        $this->generationMs = $generationMs;
        $this->tokens = $tokens;

        return $this;
    }

    public function getSourceScope(): string
    {
        return $this->sourceScope;
    }

    public function setSourceScope(string $sourceScope): self
    {
        $this->sourceScope = $sourceScope;

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

    public function getAppraisalModel(): string
    {
        return $this->appraisalModel;
    }

    public function getStatus(): ReviewStatus
    {
        return $this->status;
    }

    public function setStatus(ReviewStatus $status, ?User $reviewedBy = null): self
    {
        $this->status = $status;
        $this->reviewedBy = $reviewedBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }
}
