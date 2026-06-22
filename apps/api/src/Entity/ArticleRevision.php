<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArticleRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Version immuable d'un article wiki (nœud) : contenu complet à un instant T,
 * auteur (IA ou humain), type et résumé. Permet historique + diff en back-office.
 */
#[ORM\Entity(repositoryClass: ArticleRevisionRepository::class)]
#[ORM\Table(name: 'node_article_revision')]
#[ORM\Index(name: 'idx_nar_node', columns: ['tree_node_id', 'created_at'])]
class ArticleRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(name: 'tree_node_id', nullable: false, onDelete: 'CASCADE')]
    private TreeNode $treeNode;

    #[ORM\Column(name: 'content_md', type: Types::TEXT)]
    private string $contentMd;

    /** 'ia' | 'contributeur' | 'comite' */
    #[ORM\Column(name: 'author_type', length: 20)]
    private string $authorType = 'ia';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Libellé dénormalisé : nom du modèle (IA) ou nom affiché de l'humain. */
    #[ORM\Column(name: 'author_label', length: 255, nullable: true)]
    private ?string $authorLabel = null;

    #[ORM\Column(name: 'change_summary', length: 255, nullable: true)]
    private ?string $changeSummary = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(TreeNode $node, string $contentMd, string $authorType)
    {
        $this->treeNode = $node;
        $this->contentMd = $contentMd;
        $this->authorType = $authorType;
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

    public function getContentMd(): string
    {
        return $this->contentMd;
    }

    public function getAuthorType(): string
    {
        return $this->authorType;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getAuthorLabel(): ?string
    {
        return $this->authorLabel;
    }

    public function setAuthorLabel(?string $label): self
    {
        $this->authorLabel = $label;

        return $this;
    }

    public function getChangeSummary(): ?string
    {
        return $this->changeSummary;
    }

    public function setChangeSummary(?string $s): self
    {
        $this->changeSummary = $s;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
