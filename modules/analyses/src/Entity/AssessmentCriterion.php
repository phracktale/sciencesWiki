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

    #[ORM\Column(length: 48, nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $analysis = null;

    /** explicit_quote | inference | absence_verified | absence_from_extracted_text_only… */
    #[ORM\Column(length: 48, nullable: true)]
    private ?string $evidenceType = null;

    /** high | medium | low. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $confidence = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $requiresHumanReview = false;

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
}
