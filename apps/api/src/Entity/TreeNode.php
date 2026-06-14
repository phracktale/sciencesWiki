<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TreeNodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pgvector\Vector;

/**
 * Un nœud de l'arbre des connaissances (cf. spec §7). Amorcé depuis les concepts
 * OpenAlex, puis éditable par le comité. Porte un embedding servant de référence
 * pour la suggestion de placement (kNN).
 */
#[ORM\Entity(repositoryClass: TreeNodeRepository::class)]
#[ORM\Table(name: 'tree_node')]
#[ORM\Index(name: 'idx_tree_node_concept', columns: ['openalex_concept_id'])]
class TreeNode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(length: 512)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $domain = null;

    /** Concept OpenAlex d'origine (mapping graine), conservé après réorganisation. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $openalexConceptId = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $level = 0;

    #[ORM\Column(type: 'vector', length: 384, nullable: true)]
    private ?Vector $embedding = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $slug, string $label)
    {
        $this->slug = $slug;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
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
