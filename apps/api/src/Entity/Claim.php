<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClaimConfidence;
use App\Enum\ClaimDirection;
use App\Enum\ClaimMethod;
use App\Repository\ClaimRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pgvector\Vector;

/**
 * Assertion scientifique structurée extraite d'une publication (titre + résumé +
 * éventuelle conclusion GROBID) par le LLM. Brique de base de la détection de
 * controverses et de lacunes (cf. docs/spec-controverses-lacunes.md §4.1).
 *
 * Non décisionnelle : alimente Controversy/ResearchGap, validés par le comité.
 */
#[ORM\Entity(repositoryClass: ClaimRepository::class)]
#[ORM\Table(name: 'claim')]
#[ORM\Index(name: 'idx_claim_axis', columns: ['exposure_norm', 'outcome_norm'])]
class Claim
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $publication;

    /** Contexte thématique (placement validé) : borne le regroupement. */
    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TreeNode $treeNode = null;

    /** Variable A — facteur/exposition, libellé tel quel. */
    #[ORM\Column(length: 255)]
    private string $exposureLabel;

    /** Variable B — résultat/effet, libellé tel quel. */
    #[ORM\Column(length: 255)]
    private string $outcomeLabel;

    /** Clé normalisée (minuscules, lemmes) pour le GROUP BY exact. */
    #[ORM\Column(length: 255)]
    private string $exposureNorm;

    #[ORM\Column(length: 255)]
    private string $outcomeNorm;

    #[ORM\Column(length: 16, enumType: ClaimDirection::class)]
    private ClaimDirection $direction;

    #[ORM\Column(length: 32, enumType: ClaimMethod::class)]
    private ClaimMethod $method;

    #[ORM\Column(length: 16, enumType: ClaimConfidence::class)]
    private ClaimConfidence $confidence;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $population = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $sampleSize = null;

    /** Taille d'effet en texte libre (incl. IC), telle que rapportée. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $effectSize = null;

    /** Limites déclarées par les auteurs (signal de lacune). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $statedLimitations = null;

    /**
     * Pistes futures explicitement réclamées par les auteurs.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $futureWork = [];

    /** Phrase verbatim justifiant l'assertion (traçabilité). */
    #[ORM\Column(type: Types::TEXT)]
    private string $quote;

    /** Embedding de « exposure → outcome » (regroupement flou). */
    #[ORM\Column(type: 'vector', length: 384, nullable: true)]
    private ?Vector $embedding = null;

    /** Modèle LLM figé à l'extraction (immutabilité de la provenance). */
    #[ORM\Column(length: 128)]
    private string $extractionModel;

    /**
     * JSON brut du LLM (audit / ré-analyse).
     *
     * @var array<string,mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $raw = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $extractedAt;

    public function __construct(Publication $publication, string $extractionModel)
    {
        $this->publication = $publication;
        $this->extractionModel = $extractionModel;
        $this->extractedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): Publication
    {
        return $this->publication;
    }

    public function getTreeNode(): ?TreeNode
    {
        return $this->treeNode;
    }

    public function setTreeNode(?TreeNode $treeNode): self
    {
        $this->treeNode = $treeNode;

        return $this;
    }

    public function getExposureLabel(): string
    {
        return $this->exposureLabel;
    }

    public function setExposureLabel(string $exposureLabel): self
    {
        $this->exposureLabel = $exposureLabel;

        return $this;
    }

    public function getOutcomeLabel(): string
    {
        return $this->outcomeLabel;
    }

    public function setOutcomeLabel(string $outcomeLabel): self
    {
        $this->outcomeLabel = $outcomeLabel;

        return $this;
    }

    public function getExposureNorm(): string
    {
        return $this->exposureNorm;
    }

    public function setExposureNorm(string $exposureNorm): self
    {
        $this->exposureNorm = $exposureNorm;

        return $this;
    }

    public function getOutcomeNorm(): string
    {
        return $this->outcomeNorm;
    }

    public function setOutcomeNorm(string $outcomeNorm): self
    {
        $this->outcomeNorm = $outcomeNorm;

        return $this;
    }

    public function getDirection(): ClaimDirection
    {
        return $this->direction;
    }

    public function setDirection(ClaimDirection $direction): self
    {
        $this->direction = $direction;

        return $this;
    }

    public function getMethod(): ClaimMethod
    {
        return $this->method;
    }

    public function setMethod(ClaimMethod $method): self
    {
        $this->method = $method;

        return $this;
    }

    public function getConfidence(): ClaimConfidence
    {
        return $this->confidence;
    }

    public function setConfidence(ClaimConfidence $confidence): self
    {
        $this->confidence = $confidence;

        return $this;
    }

    public function getPopulation(): ?string
    {
        return $this->population;
    }

    public function setPopulation(?string $population): self
    {
        $this->population = null !== $population ? mb_substr($population, 0, 255) : null;

        return $this;
    }

    public function getSampleSize(): ?int
    {
        return $this->sampleSize;
    }

    public function setSampleSize(?int $sampleSize): self
    {
        $this->sampleSize = $sampleSize;

        return $this;
    }

    public function getEffectSize(): ?string
    {
        return $this->effectSize;
    }

    public function setEffectSize(?string $effectSize): self
    {
        $this->effectSize = null !== $effectSize ? mb_substr($effectSize, 0, 255) : null;

        return $this;
    }

    public function getStatedLimitations(): ?string
    {
        return $this->statedLimitations;
    }

    public function setStatedLimitations(?string $statedLimitations): self
    {
        $this->statedLimitations = $statedLimitations;

        return $this;
    }

    /** @return list<string> */
    public function getFutureWork(): array
    {
        return $this->futureWork;
    }

    /** @param list<string> $futureWork */
    public function setFutureWork(array $futureWork): self
    {
        $this->futureWork = array_values($futureWork);

        return $this;
    }

    public function getQuote(): string
    {
        return $this->quote;
    }

    public function setQuote(string $quote): self
    {
        $this->quote = $quote;

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

    public function getExtractionModel(): string
    {
        return $this->extractionModel;
    }

    /** @return array<string,mixed>|null */
    public function getRaw(): ?array
    {
        return $this->raw;
    }

    /** @param array<string,mixed>|null $raw */
    public function setRaw(?array $raw): self
    {
        $this->raw = $raw;

        return $this;
    }

    public function getExtractedAt(): \DateTimeImmutable
    {
        return $this->extractedAt;
    }
}
