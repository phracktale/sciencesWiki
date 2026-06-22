<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Enum\AnswerType;
use App\Enum\AnswerValidationStatus;
use App\Repository\AnswerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Réponse vulgarisée (Q/R) à une question (cf. spec §8.3/§8.4). L'état « validé »
 * exige une relecture comité ; « non relu » est public avec bandeau.
 *
 * L'API n'expose que les Q/R **publiques** (validées ou non relues) : les
 * brouillons en relecture sont filtrés par PublicAnswerExtension (mur §8.4).
 */
#[ORM\Entity(repositoryClass: AnswerRepository::class)]
#[ORM\Table(name: 'answer')]
#[ApiResource(
    operations: [new GetCollection(), new Get()],
    normalizationContext: ['groups' => ['answer:read']],
    paginationItemsPerPage: 30,
)]
#[ApiFilter(SearchFilter::class, properties: ['treeNode.slug' => 'exact', 'type' => 'exact'])]
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
    #[Groups(['answer:read'])]
    private string $language = 'fr';

    #[ORM\Column(length: 24, enumType: AnswerValidationStatus::class)]
    #[Groups(['answer:read'])]
    private AnswerValidationStatus $validationStatus = AnswerValidationStatus::Unreviewed;

    #[ORM\Column(length: 16, enumType: AnswerType::class)]
    #[Groups(['answer:read'])]
    private AnswerType $type = AnswerType::Canonical;

    #[ORM\Column]
    #[Groups(['answer:read'])]
    private bool $generatedByAi = true;

    /** Modèle d'IA ayant rédigé la réponse, figé à la génération (immuable ensuite). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['answer:read'])]
    private ?string $generationModel = null;

    /** Durée de génération en millisecondes (rédaction LLM). */
    #[ORM\Column(nullable: true)]
    #[Groups(['answer:read'])]
    private ?int $generationMs = null;

    /** Vrai si une source citée a été rétractée/signalée après validation : à revalider. */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['answer:read'])]
    private bool $needsRevalidation = false;

    #[ORM\Column]
    #[Groups(['answer:read'])]
    private bool $academicBlockValidated = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['answer:read'])]
    private ?\DateTimeImmutable $validatedByCommitteeAt = null;

    /** Membre du comité ayant validé (cf. spec §8.4). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['answer:read'])]
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

    #[Groups(['answer:read'])]
    public function getId(): ?int
    {
        return $this->id;
    }

    #[Groups(['answer:read'])]
    public function getQuestionText(): string
    {
        return $this->question->getText();
    }

    #[Groups(['answer:read'])]
    public function getQuestionId(): ?int
    {
        return $this->question->getId();
    }

    /** Titre court affiché (sinon, à défaut, le texte de la question). */
    #[Groups(['answer:read'])]
    public function getTitle(): string
    {
        return $this->question->getTitle() ?? $this->question->getText();
    }

    /** Nom/pseudo du demandeur (pour la pastille auteur). */
    #[Groups(['answer:read'])]
    public function getAskerName(): string
    {
        return $this->question->getAskerName() ?? 'SciencesWiki';
    }

    /**
     * @return array{slug:string,label:string}
     */
    #[Groups(['answer:read'])]
    public function getNode(): array
    {
        return ['slug' => $this->treeNode->getSlug(), 'label' => $this->treeNode->getLabel()];
    }

    #[Groups(['answer:read'])]
    public function getAcademicContent(): string
    {
        return $this->getLatestRevision()?->getAcademicContent() ?? '';
    }

    #[Groups(['answer:read'])]
    public function getVulgarizationContent(): string
    {
        return $this->getLatestRevision()?->getVulgarizationContent() ?? '';
    }

    /**
     * Notes de bas de page (sources) de la dernière révision : métadonnées
     * complètes du papier (auteurs, date, revue) + lien vers l'accès ouvert/DOI.
     * Une réponse peut citer plusieurs papiers ; un papier peut servir plusieurs
     * réponses (relation N:N côté contenu).
     *
     * @return list<array{marker:int,doi:?string,title:string,authors:list<string>,year:?string,venue:?string,oaUrl:?string}>
     */
    #[Groups(['answer:read'])]
    public function getSources(): array
    {
        $revision = $this->getLatestRevision();
        if (null === $revision) {
            return [];
        }

        $sources = [];
        foreach ($revision->getFootnotes() as $footnote) {
            $pub = $footnote->getPublication();
            $sources[] = [
                'marker' => $footnote->getMarker(),
                'doi' => $footnote->getDoi(),
                'title' => $pub->getTitle(),
                'authors' => array_map(static fn (array $a): string => $a['name'], $pub->getAuthors()),
                'year' => $pub->getPublicationDate()?->format('Y'),
                'venue' => $pub->getVenue(),
                'oaUrl' => $pub->getOaUrl(),
            ];
        }

        return $sources;
    }

    public function getQuestion(): Question
    {
        return $this->question;
    }

    public function getTreeNode(): TreeNode
    {
        return $this->treeNode;
    }

    public function setTreeNode(TreeNode $treeNode): self
    {
        $this->treeNode = $treeNode;

        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getValidationStatus(): AnswerValidationStatus
    {
        return $this->validationStatus;
    }

    public function needsRevalidation(): bool
    {
        return $this->needsRevalidation;
    }

    public function setNeedsRevalidation(bool $v): self
    {
        $this->needsRevalidation = $v;

        return $this;
    }

    public function setValidationStatus(AnswerValidationStatus $status): self
    {
        $this->validationStatus = $status;

        return $this;
    }

    public function markValidatedByCommittee(?User $reviewer = null): self
    {
        $this->academicBlockValidated = true;
        $this->validatedByCommitteeAt = new \DateTimeImmutable();
        $this->validatedBy = $reviewer;

        return $this;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    /**
     * Signataire de la réponse (cf. spec §8.6) : l'auteur humain de la version
     * courante s'il existe, sinon le modèle IA.
     */
    #[Groups(['answer:read'])]
    public function getSignature(): string
    {
        $author = $this->getLatestRevision()?->getAuthor();

        return null !== $author ? $author->getDisplayName() : 'Modèle IA — SciencesWiki';
    }

    public function getType(): AnswerType
    {
        return $this->type;
    }

    public function isGeneratedByAi(): bool
    {
        return $this->generatedByAi;
    }

    public function getGenerationModel(): ?string
    {
        return $this->generationModel;
    }

    public function setGenerationModel(?string $generationModel): self
    {
        $this->generationModel = $generationModel;

        return $this;
    }

    public function getGenerationMs(): ?int
    {
        return $this->generationMs;
    }

    public function setGenerationMs(?int $generationMs): self
    {
        $this->generationMs = null !== $generationMs ? max(0, $generationMs) : null;

        return $this;
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
