<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Enum\AnalysisStatus;
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
    #[Groups(['node:item'])]
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

    /** Image de fond du lanceur (URL) ; repli sur un dégradé si absente. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['node:read'])]
    private ?string $imageUrl = null;

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

    /** Dernière moisson ciblée de cette rubrique (pour la reprise incrémentale). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['node:read'])]
    private ?\DateTimeImmutable $lastHarvestedAt = null;

    /**
     * Cycle de vie de l'analyse « controverses & pistes » de ce nœud
     * (cf. docs/spec-controverses-lacunes.md §0.2 / §7bis). Sert aussi de verrou :
     * un seul job d'analyse par nœud à la fois (état Analyzing).
     */
    #[ORM\Column(name: 'analysis_status', length: 16, enumType: AnalysisStatus::class, options: ['default' => 'not_analyzed'])]
    #[Groups(['node:read'])]
    private AnalysisStatus $analysisStatus = AnalysisStatus::NotAnalyzed;

    /** Fin de la dernière analyse réussie ; comparé à lastHarvestedAt pour détecter Stale. */
    #[ORM\Column(name: 'analyzed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['node:read'])]
    private ?\DateTimeImmutable $analyzedAt = null;

    /** Début du job d'analyse en cours (pour le chrono/ETA affiché côté UI). */
    #[ORM\Column(name: 'analysis_started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['node:read'])]
    private ?\DateTimeImmutable $analysisStartedAt = null;

    /** Article encyclopédique long (Markdown), rédigé par IA puis relu. Version « adulte » (canonique). */
    #[ORM\Column(name: 'article_md', type: Types::TEXT, nullable: true)]
    #[Groups(['node:item'])]
    private ?string $articleMd = null;

    /** Variante « ado » (~13-17 ans) générée par IA, en lecture seule (vulgarisation accessible). */
    #[ORM\Column(name: 'article_md_ado', type: Types::TEXT, nullable: true)]
    #[Groups(['node:item'])]
    private ?string $articleMdAdo = null;

    /** Variante « chercheur » générée par IA, en lecture seule (registre technique/académique). */
    #[ORM\Column(name: 'article_md_chercheur', type: Types::TEXT, nullable: true)]
    #[Groups(['node:item'])]
    private ?string $articleMdChercheur = null;

    /** Paternité (comme les réponses) : 'non_relu' (IA seule) | 'valide' (relu humain). */
    #[ORM\Column(name: 'article_status', length: 20, options: ['default' => 'non_relu'])]
    #[Groups(['node:read'])]
    private string $articleStatus = 'non_relu';

    /** Modèle d'IA ayant rédigé l'article (paternité). */
    #[ORM\Column(name: 'article_model', length: 120, nullable: true)]
    #[Groups(['node:item'])]
    private ?string $articleModel = null;

    #[ORM\Column(name: 'article_generated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['node:read'])]
    private ?\DateTimeImmutable $articleGeneratedAt = null;

    /** Début de la (re)génération IA en cours (non-null = en cours) ; pour le loader/chrono côté UI. */
    #[ORM\Column(name: 'article_generating_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['node:read'])]
    private ?\DateTimeImmutable $articleGeneratingAt = null;

    #[ORM\Column(name: 'article_reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['node:item'])]
    private ?\DateTimeImmutable $articleReviewedAt = null;

    public function getArticleMd(): ?string
    {
        return $this->articleMd;
    }

    public function setArticleMd(?string $md): self
    {
        $this->articleMd = $md;

        return $this;
    }

    public function getArticleMdAdo(): ?string
    {
        return $this->articleMdAdo;
    }

    public function setArticleMdAdo(?string $md): self
    {
        $this->articleMdAdo = $md;

        return $this;
    }

    public function getArticleMdChercheur(): ?string
    {
        return $this->articleMdChercheur;
    }

    public function setArticleMdChercheur(?string $md): self
    {
        $this->articleMdChercheur = $md;

        return $this;
    }

    public function getArticleStatus(): string
    {
        return $this->articleStatus;
    }

    public function setArticleStatus(string $status): self
    {
        $this->articleStatus = $status;

        return $this;
    }

    public function getArticleModel(): ?string
    {
        return $this->articleModel;
    }

    public function setArticleModel(?string $model): self
    {
        $this->articleModel = $model;

        return $this;
    }

    public function getArticleGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->articleGeneratedAt;
    }

    public function setArticleGeneratedAt(?\DateTimeImmutable $at): self
    {
        $this->articleGeneratedAt = $at;

        return $this;
    }

    public function getArticleGeneratingAt(): ?\DateTimeImmutable
    {
        return $this->articleGeneratingAt;
    }

    public function setArticleGeneratingAt(?\DateTimeImmutable $at): self
    {
        $this->articleGeneratingAt = $at;

        return $this;
    }

    public function getArticleReviewedAt(): ?\DateTimeImmutable
    {
        return $this->articleReviewedAt;
    }

    public function setArticleReviewedAt(?\DateTimeImmutable $at): self
    {
        $this->articleReviewedAt = $at;

        return $this;
    }

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
            $children[] = ['id' => $node->getId(), 'slug' => $node->getSlug(), 'label' => $node->getLabel(), 'level' => $node->getLevel()];
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
            array_unshift($chain, ['id' => $node->getId(), 'slug' => $node->getSlug(), 'label' => $node->getLabel()]);
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

    #[Groups(['node:read'])]
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

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

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

    #[Groups(['node:read'])]
    public function getOpenalexConceptId(): ?string
    {
        return $this->openalexConceptId;
    }

    /** URL publique OpenAlex du concept (mapping graine), si disponible. */
    #[Groups(['node:read'])]
    public function getOpenalexUrl(): ?string
    {
        return null !== $this->openalexConceptId ? 'https://openalex.org/'.$this->openalexConceptId : null;
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

    public function getLastHarvestedAt(): ?\DateTimeImmutable
    {
        return $this->lastHarvestedAt;
    }

    public function markHarvested(): self
    {
        $this->lastHarvestedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAnalysisStatus(): AnalysisStatus
    {
        return $this->analysisStatus;
    }

    public function setAnalysisStatus(AnalysisStatus $status): self
    {
        $this->analysisStatus = $status;

        return $this;
    }

    public function getAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    public function getAnalysisStartedAt(): ?\DateTimeImmutable
    {
        return $this->analysisStartedAt;
    }

    /** Début d'un job d'analyse : horodate le départ (chrono/ETA UI). */
    public function markAnalysisStarted(): self
    {
        $this->analysisStartedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Fin d'une analyse réussie : passe le nœud à Ready et horodate. */
    public function markAnalyzed(): self
    {
        $this->analysisStatus = AnalysisStatus::Ready;
        $this->analyzedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Le nœud a-t-il été moissonné depuis sa dernière analyse ? (⇒ Stale).
     * Sans analyse antérieure, rien à rafraîchir.
     */
    public function isAnalysisStale(): bool
    {
        if (null === $this->analyzedAt || null === $this->lastHarvestedAt) {
            return false;
        }

        return $this->lastHarvestedAt > $this->analyzedAt;
    }
}
