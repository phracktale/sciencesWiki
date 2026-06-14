<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Arête du graphe orienté acyclique (DAG) de l'arbre des connaissances : un nœud
 * peut avoir plusieurs parents (cf. spec §7). Le parent « principal » sert à
 * l'URL canonique (SEO).
 */
#[ORM\Entity]
#[ORM\Table(name: 'tree_edge')]
#[ORM\UniqueConstraint(name: 'uniq_tree_edge', columns: ['parent_id', 'child_id'])]
class TreeEdge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TreeNode::class, inversedBy: 'childEdges')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TreeNode $parent;

    #[ORM\ManyToOne(targetEntity: TreeNode::class, inversedBy: 'parentEdges')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TreeNode $child;

    #[ORM\Column]
    private bool $principal = false;

    public function __construct(TreeNode $parent, TreeNode $child, bool $principal = false)
    {
        $this->parent = $parent;
        $this->child = $child;
        $this->principal = $principal;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParent(): TreeNode
    {
        return $this->parent;
    }

    public function getChild(): TreeNode
    {
        return $this->child;
    }

    public function isPrincipal(): bool
    {
        return $this->principal;
    }

    public function setPrincipal(bool $principal): self
    {
        $this->principal = $principal;

        return $this;
    }
}
