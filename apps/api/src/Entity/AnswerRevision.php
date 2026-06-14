<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RevisionAuthorType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Version immuable d'une réponse (cf. spec §8.4/§8.6). L'auteur peut être l'IA,
 * un membre du comité ou un contributeur.
 */
#[ORM\Entity]
#[ORM\Table(name: 'answer_revision')]
class AnswerRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Answer::class, inversedBy: 'revisions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Answer $answer = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $academicContent = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $vulgarizationContent = '';

    #[ORM\Column(length: 16, enumType: RevisionAuthorType::class)]
    private RevisionAuthorType $authorType;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $changeSummary = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $parentRevision = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int,Footnote> */
    #[ORM\OneToMany(targetEntity: Footnote::class, mappedBy: 'answerRevision', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['marker' => 'ASC'])]
    private Collection $footnotes;

    public function __construct(RevisionAuthorType $authorType)
    {
        $this->authorType = $authorType;
        $this->createdAt = new \DateTimeImmutable();
        $this->footnotes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnswer(): ?Answer
    {
        return $this->answer;
    }

    public function setAnswer(?Answer $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    public function getAcademicContent(): string
    {
        return $this->academicContent;
    }

    public function setAcademicContent(string $academicContent): self
    {
        $this->academicContent = $academicContent;

        return $this;
    }

    public function getVulgarizationContent(): string
    {
        return $this->vulgarizationContent;
    }

    public function setVulgarizationContent(string $vulgarizationContent): self
    {
        $this->vulgarizationContent = $vulgarizationContent;

        return $this;
    }

    public function getAuthorType(): RevisionAuthorType
    {
        return $this->authorType;
    }

    public function getChangeSummary(): ?string
    {
        return $this->changeSummary;
    }

    public function setChangeSummary(?string $changeSummary): self
    {
        $this->changeSummary = $changeSummary;

        return $this;
    }

    public function setParentRevision(?self $parentRevision): self
    {
        $this->parentRevision = $parentRevision;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int,Footnote> */
    public function getFootnotes(): Collection
    {
        return $this->footnotes;
    }

    public function addFootnote(Footnote $footnote): self
    {
        if (!$this->footnotes->contains($footnote)) {
            $this->footnotes->add($footnote);
            $footnote->setAnswerRevision($this);
        }

        return $this;
    }
}
