<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PlacementStatus;
use App\Repository\PlacementSuggestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Proposition (non décisionnelle) de placement d'une publication dans un nœud de
 * l'arbre, issue de la similarité d'embeddings (kNN). Validée par un humain
 * (cf. spec §6.3, Phase 1 §8).
 */
#[ORM\Entity(repositoryClass: PlacementSuggestionRepository::class)]
#[ORM\Table(name: 'placement_suggestion')]
#[ORM\UniqueConstraint(name: 'uniq_placement', columns: ['publication_id', 'tree_node_id'])]
class PlacementSuggestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $publication;

    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TreeNode $treeNode;

    /** Score de similarité (1 = identique, 0 = orthogonal) dérivé de la distance cosinus. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $score;

    #[ORM\Column(length: 16)]
    private string $method = 'knn';

    #[ORM\Column(length: 16, enumType: PlacementStatus::class)]
    private PlacementStatus $status = PlacementStatus::Proposed;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Publication $publication, TreeNode $treeNode, float $score, string $method = 'knn')
    {
        $this->publication = $publication;
        $this->treeNode = $treeNode;
        $this->score = $score;
        $this->method = $method;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): Publication
    {
        return $this->publication;
    }

    public function getTreeNode(): TreeNode
    {
        return $this->treeNode;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getStatus(): PlacementStatus
    {
        return $this->status;
    }

    public function setStatus(PlacementStatus $status): self
    {
        $this->status = $status;

        return $this;
    }
}
