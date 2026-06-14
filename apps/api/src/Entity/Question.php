<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuestionOrigin;
use App\Repository\QuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pgvector\Vector;

/**
 * Une question rattachée à un nœud (cf. spec §8.2). Unité de la vulgarisation
 * pilotée par les questions.
 */
#[ORM\Entity(repositoryClass: QuestionRepository::class)]
#[ORM\Table(name: 'question')]
class Question
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: false)]
    private TreeNode $treeNode;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(type: 'vector', length: 384, nullable: true)]
    private ?Vector $embedding = null;

    #[ORM\Column(length: 24, enumType: QuestionOrigin::class)]
    private QuestionOrigin $origin = QuestionOrigin::SuggeredByAi;

    #[ORM\Column]
    private int $askCount = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(TreeNode $treeNode, string $text, QuestionOrigin $origin = QuestionOrigin::SuggeredByAi)
    {
        $this->treeNode = $treeNode;
        $this->text = $text;
        $this->origin = $origin;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTreeNode(): TreeNode
    {
        return $this->treeNode;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getEmbedding(): ?Vector
    {
        return $this->embedding;
    }

    /** @param list<float>|Vector|null $embedding */
    public function setEmbedding(array|Vector|null $embedding): self
    {
        $this->embedding = \is_array($embedding) ? new Vector($embedding) : $embedding;

        return $this;
    }

    public function getOrigin(): QuestionOrigin
    {
        return $this->origin;
    }

    public function getAskCount(): int
    {
        return $this->askCount;
    }

    public function incrementAskCount(): self
    {
        ++$this->askCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
