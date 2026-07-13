<?php

declare(strict_types=1);

namespace Analyses\Entity;

use Analyses\Repository\AssessmentCriterionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Réponse à un critère d'un référentiel (SPECS §18). Rattachée à une évaluation par
 * son ULID (pas de FK dure). Table préfixée analys_.
 */
#[ORM\Entity(repositoryClass: AssessmentCriterionRepository::class)]
#[ORM\Table(name: 'analys_assessment_criterion')]
#[ORM\Index(name: 'idx_analys_crit_assessment', columns: ['assessment_id'])]
class AssessmentCriterion
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $id;

    #[ORM\Column(type: 'ulid')]
    private Ulid $assessmentId;

    #[ORM\Column(length: 64)]
    private string $frameworkId;

    #[ORM\Column(length: 64)]
    private string $criterionId;

    #[ORM\Column(length: 96, nullable: true)]
    private ?string $dimension = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $question;

    /** yes | partial | no | unclear | not_applicable (SPECS §2.4). */
    #[ORM\Column(length: 24)]
    private string $answer = 'unclear';

    #[ORM\Column(length: 96, nullable: true)]
    private ?string $verdict = null;

    /** Ce que la grille EXIGE pour un « oui » à cet item (doctrine legacy AXIS). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $expected = null;

    /** Ce que l'article fournit réellement sur ce point. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidenceFound = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $analysis = null;

    /** Ce qui manque / est ambigu / repose sur une inférence. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $limitations = null;

    /** Type de preuve global de l'item (ex. mixed_explicit_and_absence). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $overallEvidenceType = null;

    /** La réponse dépend probablement d'un tableau/figure non transcrit. */
    #[ORM\Column(options: ['default' => false])]
    private bool $requiresVisualCheck = false;

    /** explicit_quote | inference | absence_verified | absence_from_extracted_text_only… */
    #[ORM\Column(length: 48, nullable: true)]
    private ?string $evidenceType = null;

    /** high | medium | low. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $confidence = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $requiresHumanReview = false;

    /** Réponse corrigée par un relecteur (prime sur la réponse IA). */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $humanAnswer = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct(Ulid $assessmentId, string $frameworkId, string $criterionId, string $question)
    {
        $this->id = new Ulid();
        $this->assessmentId = $assessmentId;
        $this->frameworkId = $frameworkId;
        $this->criterionId = $criterionId;
        $this->question = $question;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getAssessmentId(): Ulid
    {
        return $this->assessmentId;
    }

    public function getFrameworkId(): string
    {
        return $this->frameworkId;
    }

    public function getCriterionId(): string
    {
        return $this->criterionId;
    }

    public function getDimension(): ?string
    {
        return $this->dimension;
    }

    public function setDimension(?string $dimension): self
    {
        $this->dimension = $dimension;

        return $this;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    public function setAnswer(string $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    public function getVerdict(): ?string
    {
        return $this->verdict;
    }

    public function setVerdict(?string $verdict): self
    {
        $this->verdict = $verdict;

        return $this;
    }

    public function getAnalysis(): ?string
    {
        return $this->analysis;
    }

    public function setAnalysis(?string $analysis): self
    {
        $this->analysis = $analysis;

        return $this;
    }

    public function getExpected(): ?string
    {
        return $this->expected;
    }

    public function setExpected(?string $expected): self
    {
        $this->expected = $expected;

        return $this;
    }

    public function getEvidenceFound(): ?string
    {
        return $this->evidenceFound;
    }

    public function setEvidenceFound(?string $evidenceFound): self
    {
        $this->evidenceFound = $evidenceFound;

        return $this;
    }

    public function getLimitations(): ?string
    {
        return $this->limitations;
    }

    public function setLimitations(?string $limitations): self
    {
        $this->limitations = $limitations;

        return $this;
    }

    public function getOverallEvidenceType(): ?string
    {
        return $this->overallEvidenceType;
    }

    public function setOverallEvidenceType(?string $overallEvidenceType): self
    {
        $this->overallEvidenceType = $overallEvidenceType;

        return $this;
    }

    public function requiresVisualCheck(): bool
    {
        return $this->requiresVisualCheck;
    }

    public function setRequiresVisualCheck(bool $requiresVisualCheck): self
    {
        $this->requiresVisualCheck = $requiresVisualCheck;

        return $this;
    }

    public function getEvidenceType(): ?string
    {
        return $this->evidenceType;
    }

    public function setEvidenceType(?string $evidenceType): self
    {
        $this->evidenceType = $evidenceType;

        return $this;
    }

    public function getConfidence(): ?string
    {
        return $this->confidence;
    }

    public function setConfidence(?string $confidence): self
    {
        $this->confidence = $confidence;

        return $this;
    }

    public function requiresHumanReview(): bool
    {
        return $this->requiresHumanReview;
    }

    public function setRequiresHumanReview(bool $requiresHumanReview): self
    {
        $this->requiresHumanReview = $requiresHumanReview;

        return $this;
    }

    public function getHumanAnswer(): ?string
    {
        return $this->humanAnswer;
    }

    public function getReviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    /** Réponse effective : la correction humaine prime sur la réponse IA. */
    public function effectiveAnswer(): string
    {
        return $this->humanAnswer ?? $this->answer;
    }

    /** Enregistre une correction humaine (relecteur), avec traçabilité. */
    public function applyHumanReview(string $answer, ?string $analysis, string $reviewer): self
    {
        $this->humanAnswer = $answer;
        if (null !== $analysis) {
            $this->analysis = $analysis;
        }
        $this->reviewedBy = $reviewer;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->requiresHumanReview = false;

        return $this;
    }
}
