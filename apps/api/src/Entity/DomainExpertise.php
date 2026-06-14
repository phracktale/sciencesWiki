<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rattache un utilisateur (comité scientifique / relecteur) à un nœud de l'arbre :
 * périmètre où il peut valider (cf. spec §9.4). Le comité est « élargi à chaque
 * domaine de compétence ».
 */
#[ORM\Entity]
#[ORM\Table(name: 'domain_expertise')]
#[ORM\UniqueConstraint(name: 'uniq_expertise', columns: ['user_id', 'tree_node_id'])]
class DomainExpertise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'expertise')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TreeNode $treeNode;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    public function __construct(TreeNode $treeNode)
    {
        $this->treeNode = $treeNode;
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getTreeNode(): TreeNode
    {
        return $this->treeNode;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }
}
