<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\TreeNodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pgvector\Vector;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Un nœud de l'arbre des connaissances (cf. spec §7). Amorcé depuis la taxonomie
 * OpenAlex, puis éditable par le comité. Porte un embedding servant de référence
 * pour la suggestion de placement (kNN).
 */
#[ORM\Entity(repositoryClass: TreeNodeRepository::class)]
#[ORM\Table(name: 'tree_node')]
#[ORM\Index(name: 'idx_tree_node_concept', columns: ['openalex_concept_id'])]
#[ApiResource(
    shortName: 'TreeNode',
    operations: [
        new GetCollection(),
        new Get(
            uriTemplate: '/tree_nodes/{slug}',
            uriVariables: ['slug'],
            normalizationContext: ['groups' => ['node:read', 'node:item']],
        ),
    ],
    normalizationContext: ['groups' => ['node:read']],
    paginationItemsPerPage: 50,
)]
#[ApiFilter(SearchFilter::class, properties: ['level' => 'exact', 'domain' => 'exact', 'label' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['label', 'level'])]
class TreeNode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Groups(['node:read'])]
    private string $slug;

    #[ORM\Column(length: 512)]
    #[Groups(['node:read'])]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['node:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['node:read'])]
    private ?string $domain = null;

    /** Concept OpenAlex d'origine (mapping graine), conservé après réorganisation. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $openalexConceptId = null;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Groups(['node:read'])]
    private int $level = 0;

    /** @var Collection<int,TreeEdge> arêtes où ce nœud est parent */
    #[ORM\OneToMany(targetEntity: TreeEdge::class, mappedBy: 'parent')]
    private Collection $childEdges;

    /** @var Collection<int,TreeEdge> arêtes où ce nœud est enfant */
    #[ORM\OneToMany(targetEntity: TreeEdge::class, mappedBy: 'child')]
    private Collection $parentEdges;

    #[ORM\Column(type: 'vector', length: 384, nullable: true)]
    private ?Vector $embedding = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $slug, string $label)
    {
        $this->slug = $slug;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
        $this->childEdges = new ArrayCollection();
        $this->parentEdges = new ArrayCollection();
    }

    /**
     * Enfants directs (DAG), forme légère pour l'API.
     *
     * @return list<array{slug:string,label:string,level:int}>
     */
    #[Groups(['node:item'])]
    public function getChildren(): array
    {
        $children = [];
        foreach ($this->childEdges as $edge) {
            $node = $edge->getChild();
            $children[] = ['slug' => $node->getSlug(), 'label' => $node->getLabel(), 'level' => $node->getLevel()];
        }
        usort($children, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        return $children;
    }

    /**
     * Parents directs (fil d'Ariane du DAG), forme légère pour l'API.
     *
     * @return list<array{slug:string,label:string,level:int}>
     */
    #[Groups(['node:item'])]
    public function getParents(): array
    {
        $parents = [];
        foreach ($this->parentEdges as $edge) {
            $node = $edge->getParent();
            $parents[] = ['slug' => $node->getSlug(), 'label' => $node->getLabel(), 'level' => $node->getLevel()];
        }

        return $parents;
    }

    #[Groups(['node:read'])]
    public function getChildrenCount(): int
    {
        return $this->childEdges->count();
    }

    /**
     * Fil d'Ariane canonique (racine → ce nœud), en remontant les parents
     * « principaux ». Sert à l'affichage et aux URLs arborescentes (SEO).
     *
     * @return list<array{slug:string,label:string}>
     */
    #[Groups(['node:item'])]
    public function getBreadcrumb(): array
    {
        $chain = [];
        $node = $this;
        $guard = 0;
        while (null !== $node && $guard++ < 12) {
            array_unshift($chain, ['slug' => $node->getSlug(), 'label' => $node->getLabel()]);
            $node = $node->principalParent();
        }

        return $chain;
    }

    private function principalParent(): ?self
    {
        $fallback = null;
        foreach ($this->parentEdges as $edge) {
            if ($edge->isPrincipal()) {
                return $edge->getParent();
            }
            $fallback ??= $edge->getParent();
        }

        return $fallback;
    }

    /** @return Collection<int,TreeEdge> arêtes où ce nœud est parent */
    public function getChildEdges(): Collection
    {
        return $this->childEdges;
    }

    /** @return Collection<int,TreeEdge> arêtes où ce nœud est enfant */
    public function getParentEdges(): Collection
    {
        return $this->parentEdges;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    public function getOpenalexConceptId(): ?string
    {
        return $this->openalexConceptId;
    }

    public function setOpenalexConceptId(?string $openalexConceptId): self
    {
        $this->openalexConceptId = $openalexConceptId;

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = $level;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
