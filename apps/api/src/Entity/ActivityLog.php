<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entrée du journal d'audit : qui a fait quoi et quand. Transversal (moissons,
 * questions humaines/IA, réponses, modifications admin, utilisateurs, réglages).
 */
#[ORM\Entity(repositoryClass: ActivityLogRepository::class)]
#[ORM\Table(name: 'activity_log')]
#[ORM\Index(name: 'idx_activity_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_activity_category', columns: ['category'])]
class ActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** Famille d'événement : harvest, question, answer, node, user, settings, system. */
    #[ORM\Column(length: 32)]
    private string $category;

    /** Action précise : ask, suggest, harvest_start, rename, move, delete, create… */
    #[ORM\Column(length: 64)]
    private string $action;

    /** Acteur : e-mail admin, « IA », « visiteur », « system ». */
    #[ORM\Column(length: 255)]
    private string $actor = 'system';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /** @param array<string,mixed>|null $context */
    public function __construct(string $category, string $action, string $actor = 'system', ?string $summary = null, ?array $context = null, ?string $ip = null)
    {
        $this->occurredAt = new \DateTimeImmutable();
        $this->category = $category;
        $this->action = $action;
        $this->actor = $actor;
        $this->summary = $summary;
        $this->context = $context;
        $this->ip = $ip;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /** @return array<string,mixed>|null */
    public function getContext(): ?array
    {
        return $this->context;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }
}
