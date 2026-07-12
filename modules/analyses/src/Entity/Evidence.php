<?php

declare(strict_types=1);

namespace Analyses\Entity;

use Analyses\Repository\EvidenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Preuve documentaire stockée séparément (SPECS §17) : citation localisable ancrant
 * une réponse. Rattachée à l'évaluation (et éventuellement à un critère) par ULID.
 */
#[ORM\Entity(repositoryClass: EvidenceRepository::class)]
#[ORM\Table(name: 'analys_evidence')]
#[ORM\Index(name: 'idx_analys_evidence_assessment', columns: ['assessment_id'])]
class Evidence
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $id;

    #[ORM\Column(type: 'ulid')]
    private Ulid $assessmentId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $criterionId = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $quote;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $normalizedFact = null;

    /** explicit_quote | table_value | figure_observation | metadata | inference | absence_verified… */
    #[ORM\Column(length: 48)]
    private string $evidenceType = 'explicit_quote';

    /** high | medium | low. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $confidence = null;

    #[ORM\Column(length: 96, nullable: true)]
    private ?string $section = null;

    public function __construct(Ulid $assessmentId, string $quote, string $evidenceType = 'explicit_quote')
    {
        $this->id = new Ulid();
        $this->assessmentId = $assessmentId;
        $this->quote = $quote;
        $this->evidenceType = $evidenceType;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getAssessmentId(): Ulid
    {
        return $this->assessmentId;
    }

    public function getCriterionId(): ?string
    {
        return $this->criterionId;
    }

    public function setCriterionId(?string $criterionId): self
    {
        $this->criterionId = $criterionId;

        return $this;
    }

    public function getQuote(): string
    {
        return $this->quote;
    }

    public function getNormalizedFact(): ?string
    {
        return $this->normalizedFact;
    }

    public function setNormalizedFact(?string $normalizedFact): self
    {
        $this->normalizedFact = $normalizedFact;

        return $this;
    }

    public function getEvidenceType(): string
    {
        return $this->evidenceType;
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

    public function getSection(): ?string
    {
        return $this->section;
    }

    public function setSection(?string $section): self
    {
        $this->section = $section;

        return $this;
    }
}
