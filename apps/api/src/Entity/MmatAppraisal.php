<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReviewStatus;
use App\Repository\MmatAppraisalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Évaluation de la qualité méthodologique d'une étude EMPIRIQUE par l'outil MMAT
 * (Mixed Methods Appraisal Tool, Hong et al. 2018) : 2 questions de filtrage + 5
 * critères propres à la CATÉGORIE détectée (qualitative, essai randomisé, non
 * randomisée, descriptive, méthodes mixtes). Mêmes garanties qu'AXIS/AMSTAR-2 :
 * modèle figé, garde-fou citation, verrou d'applicabilité, gating comité.
 */
#[ORM\Entity(repositoryClass: MmatAppraisalRepository::class)]
#[ORM\Table(name: 'mmat_appraisal')]
#[ORM\UniqueConstraint(name: 'uniq_mmat_publication', columns: ['publication_id'])]
#[ORM\Index(name: 'idx_mmat_status', columns: ['status'])]
class MmatAppraisal
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

    /** applicable | not_applicable | uncertain (étude empirique évaluable par MMAT ou non). */
    #[ORM\Column(length: 16)]
    private string $applicability = 'uncertain';

    /** Catégorie MMAT retenue : qualitative | quant_rct | quant_nonrandomized | quant_descriptive | mixed_methods. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $studyDesign = null;

    /**
     * Réponses aux 2 questions de filtrage + 5 critères : { "s1": "yes|no|cant_tell", …, "c5": … }.
     *
     * @var array<string,string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    /**
     * Citation verbatim par item (traçabilité). { "c1": "phrase exacte…" }.
     *
     * @var array<string,string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $justifications = [];

    /** Les 2 questions de filtrage sont-elles satisfaites (« oui » aux deux) ? */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $screeningPassed = false;

    /** Nombre de critères remplis (« oui ») sur les 5 — indicateur seulement. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $metCount = 0;

    /** Repère de qualité indicatif : high | moderate | low | insufficient. */
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = null !== $category ? mb_substr($category, 0, 32) : null;

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

    public function isScreeningPassed(): bool
    {
        return $this->screeningPassed;
    }

    public function getMetCount(): int
    {
        return $this->metCount;
    }

    public function getOverall(): ?string
    {
        return $this->overall;
    }

    public function setScoring(bool $screeningPassed, int $metCount, ?string $overall): self
    {
        $this->screeningPassed = $screeningPassed;
        $this->metCount = $metCount;
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
