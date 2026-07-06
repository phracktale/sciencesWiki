<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SubmissionStatus;
use App\Repository\CorpusSubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Proposition d'ajout au corpus d'une étude déposée par un utilisateur (PDF uploadé
 * pour évaluation critique). NON DÉCISIONNEL côté utilisateur : le comité tranche.
 * Tant que le statut n'est pas « Approved », la publication liée reste PRIVÉE
 * (listedInCorpus=false, invisible des recherches). L'acceptation déclenche
 * l'intégration (embedding + placement) par les drains habituels.
 */
#[ORM\Entity(repositoryClass: CorpusSubmissionRepository::class)]
#[ORM\Table(name: 'corpus_submission')]
#[ORM\UniqueConstraint(name: 'uniq_submission_pub', columns: ['publication_id'])]
#[ORM\Index(name: 'idx_submission_status', columns: ['status'])]
class CorpusSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** L'étude déposée dont on demande l'intégration au corpus public. */
    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $publication;

    /** Utilisateur ayant demandé l'ajout (l'uploadeur). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $submittedBy;

    /** Justification libre de l'uploadeur (optionnelle). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 16, enumType: SubmissionStatus::class)]
    private SubmissionStatus $status = SubmissionStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct(Publication $publication, ?User $submittedBy, ?string $note = null)
    {
        $this->publication = $publication;
        $this->submittedBy = $submittedBy;
        $this->note = $note;
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): Publication
    {
        return $this->publication;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getStatus(): SubmissionStatus
    {
        return $this->status;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function review(SubmissionStatus $status, ?User $reviewedBy): self
    {
        $this->status = $status;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = new \DateTimeImmutable();

        return $this;
    }
}
