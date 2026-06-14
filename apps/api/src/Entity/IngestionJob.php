<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IngestionStatus;
use App\Repository\IngestionJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'une exécution de moisson (audit, compteurs, reprise incrémentale —
 * cf. spec §9.1 et Phase 1 §10).
 */
#[ORM\Entity(repositoryClass: IngestionJobRepository::class)]
#[ORM\Table(name: 'ingestion_job')]
class IngestionJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Source $source;

    /** @var array<string,mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $query = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private int $processed = 0;

    #[ORM\Column]
    private int $created = 0;

    #[ORM\Column]
    private int $errors = 0;

    #[ORM\Column(length: 16, enumType: IngestionStatus::class)]
    private IngestionStatus $status = IngestionStatus::Running;

    /** Curseur atteint en fin d'exécution, pour reprendre la moisson incrémentale. */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $endCursor = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $log = null;

    /** @param array<string,mixed> $query */
    public function __construct(Source $source, array $query = [])
    {
        $this->source = $source;
        $this->query = $query;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): Source
    {
        return $this->source;
    }

    /** @return array<string,mixed> */
    public function getQuery(): array
    {
        return $this->query;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getErrors(): int
    {
        return $this->errors;
    }

    public function getStatus(): IngestionStatus
    {
        return $this->status;
    }

    public function getEndCursor(): ?string
    {
        return $this->endCursor;
    }

    public function setEndCursor(?string $endCursor): self
    {
        $this->endCursor = $endCursor;

        return $this;
    }

    public function getLog(): ?string
    {
        return $this->log;
    }

    public function countProcessed(): void
    {
        ++$this->processed;
    }

    public function countCreated(): void
    {
        ++$this->created;
    }

    public function countError(): void
    {
        ++$this->errors;
    }

    public function finish(?string $log = null): void
    {
        $this->finishedAt = new \DateTimeImmutable();
        $this->status = $this->errors > 0 ? IngestionStatus::Partial : IngestionStatus::Ok;
        $this->log = $log;
    }

    public function fail(string $log): void
    {
        $this->finishedAt = new \DateTimeImmutable();
        $this->status = IngestionStatus::Failed;
        $this->log = $log;
    }
}
