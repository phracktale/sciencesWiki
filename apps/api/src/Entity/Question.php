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

    /** Titre court affiché (ex. « Apprentissage profond résiduel »), posé par le rédacteur IA. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    /** Nom/pseudo du demandeur (obligatoire pour une question libre publique). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $askerName = null;

    /** IP du demandeur (audit + rate-limit). Donnée personnelle : minimisation RGPD. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $askerIp = null;

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

    /** Réorientation (spec §8.2) : rattacher la question au bon nœud. */
    public function setTreeNode(TreeNode $treeNode): self
    {
        $this->treeNode = $treeNode;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = null !== $title ? mb_substr($title, 0, 255) : null;

        return $this;
    }

    public function getAskerName(): ?string
    {
        return $this->askerName;
    }

    public function setAskerName(?string $askerName): self
    {
        $this->askerName = null !== $askerName ? mb_substr($askerName, 0, 120) : null;

        return $this;
    }

    public function getAskerIp(): ?string
    {
        return $this->askerIp;
    }

    public function setAskerIp(?string $askerIp): self
    {
        $this->askerIp = $askerIp;

        return $this;
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
