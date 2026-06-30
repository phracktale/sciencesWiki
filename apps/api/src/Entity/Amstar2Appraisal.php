<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReviewStatus;
use App\Repository\Amstar2AppraisalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Évaluation de la confiance dans une REVUE SYSTÉMATIQUE par l'outil AMSTAR-2 (Shea
 * et al., BMJ 2017). 16 items (oui/oui partiel/non) dont 7 critiques → niveau de
 * confiance global. Mêmes garanties qu'AXIS : modèle figé, garde-fou citation,
 * verrou d'applicabilité (revues systématiques seulement), gating comité.
 */
#[ORM\Entity(repositoryClass: Amstar2AppraisalRepository::class)]
#[ORM\Table(name: 'amstar2_appraisal')]
#[ORM\UniqueConstraint(name: 'uniq_amstar2_publication', columns: ['publication_id'])]
#[ORM\Index(name: 'idx_amstar2_status', columns: ['status'])]
class Amstar2Appraisal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $publication;

    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TreeNode $treeNode = null;

    /** applicable | not_applicable | uncertain (revue systématique ou non). */
    #[ORM\Column(length: 16)]
    private string $applicability = 'uncertain';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $studyDesign = null;

    /**
     * Réponses aux 16 items : { "q1": "yes|partial_yes|no", … }.
     *
     * @var array<string,string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    /**
     * Citation verbatim par item (traçabilité). { "q4": "phrase exacte…" }.
     *
     * @var array<string,string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $justifications = [];

    /** Défauts CRITIQUES (« non » sur un item critique). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $criticalFlaws = 0;

    /** Réserves non critiques (« non » sur un item non critique). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $weaknesses = 0;

    /** Niveau de confiance global : high | moderate | low | critically_low. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $overall = null;

    #[ORM\Column(length: 24)]
    private string $sourceScope = 'abstract';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(length: 128)]
    private string $appraisalModel;

    #[ORM\Column(length: 16, enumType: ReviewStatus::class)]
    private ReviewStatus $status = ReviewStatus::Detected;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

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

    public function setTreeNode(?TreeNode $treeNode): self
    {
        $this->treeNode = $treeNode;

        return $this;
    }

    public function getApplicability(): string
    {
        return $this->applicability;
    }

    public function setApplicability(string $applicability): self
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

    public function getCriticalFlaws(): int
    {
        return $this->criticalFlaws;
    }

    public function getWeaknesses(): int
    {
        return $this->weaknesses;
    }

    public function getOverall(): ?string
    {
        return $this->overall;
    }

    public function setScoring(int $criticalFlaws, int $weaknesses, ?string $overall): self
    {
        $this->criticalFlaws = $criticalFlaws;
        $this->weaknesses = $weaknesses;
        $this->overall = $overall;

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
}
