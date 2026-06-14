<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AnswerType;
use App\Enum\AnswerValidationStatus;
use App\Repository\AnswerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réponse vulgarisée (Q/R) à une question (cf. spec §8.3/§8.4). L'état « validé »
 * exige une relecture comité ; « non relu » est public avec bandeau.
 */
#[ORM\Entity(repositoryClass: AnswerRepository::class)]
#[ORM\Table(name: 'answer')]
class Answer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Question::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Question $question;

    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: false)]
    private TreeNode $treeNode;

    #[ORM\Column(length: 8)]
    private string $language = 'fr';

    #[ORM\Column(length: 24, enumType: AnswerValidationStatus::class)]
    private AnswerValidationStatus $validationStatus = AnswerValidationStatus::Unreviewed;

    #[ORM\Column(length: 16, enumType: AnswerType::class)]
    private AnswerType $type = AnswerType::Canonical;

    #[ORM\Column]
    private bool $generatedByAi = true;

    #[ORM\Column]
    private bool $academicBlockValidated = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedByCommitteeAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int,AnswerRevision> */
    #[ORM\OneToMany(targetEntity: AnswerRevision::class, mappedBy: 'answer', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $revisions;

    public function __construct(Question $question, AnswerType $type)
    {
        $this->question = $question;
        $this->treeNode = $question->getTreeNode();
        $this->type = $type;
        // Une Q/R libre est publique « non relue » ; une canonique part en relecture.
        $this->validationStatus = AnswerType::Free === $type
            ? AnswerValidationStatus::Unreviewed
            : AnswerValidationStatus::InCommitteeReview;
        $this->createdAt = new \DateTimeImmutable();
        $this->revisions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): Question
    {
        return $this->question;
    }

    public function getTreeNode(): TreeNode
    {
        return $this->treeNode;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getValidationStatus(): AnswerValidationStatus
    {
        return $this->validationStatus;
    }

    public function setValidationStatus(AnswerValidationStatus $status): self
    {
        $this->validationStatus = $status;

        return $this;
    }

    public function getType(): AnswerType
    {
        return $this->type;
    }

    public function isGeneratedByAi(): bool
    {
        return $this->generatedByAi;
    }

    public function isAcademicBlockValidated(): bool
    {
        return $this->academicBlockValidated;
    }

    public function getValidatedByCommitteeAt(): ?\DateTimeImmutable
    {
        return $this->validatedByCommitteeAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int,AnswerRevision> */
    public function getRevisions(): Collection
    {
        return $this->revisions;
    }

    public function addRevision(AnswerRevision $revision): self
    {
        if (!$this->revisions->contains($revision)) {
            $this->revisions->add($revision);
            $revision->setAnswer($this);
        }

        return $this;
    }

    public function getLatestRevision(): ?AnswerRevision
    {
        return $this->revisions->last() ?: null;
    }
}
