<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use App\Enum\OaStatus;
use App\Enum\ProcessingStatus;
use App\Enum\RetractionStatus;
use App\Repository\PublicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pgvector\Vector;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Un travail scientifique moissonné. Clé de dédoublonnage : le DOI normalisé
 * (cf. spec §9.1, et Phase 1 §5–§6).
 */
#[ORM\Entity(repositoryClass: PublicationRepository::class)]
#[ORM\Table(name: 'publication')]
#[ORM\Index(name: 'idx_publication_status', columns: ['processing_status'])]
#[ApiResource(
    operations: [new GetCollection(), new Get()],
    normalizationContext: ['groups' => ['publication:read']],
    paginationItemsPerPage: 30,
)]
#[ApiFilter(OrderFilter::class, properties: ['publicationDate', 'id'])]
class Publication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** DOI normalisé (minuscule, sans préfixe URL) ; absent pour certains préprints. */
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    #[Groups(['publication:read'])]
    private ?string $doi = null;

    /** @var array<string,string> */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['publication:read'])]
    private array $externalIds = [];

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['publication:read'])]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['publication:read'])]
    private ?string $abstract = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Groups(['publication:read'])]
    private ?\DateTimeImmutable $publicationDate = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['publication:read'])]
    private ?string $language = null;

    #[ORM\Column(length: 512, nullable: true)]
    #[Groups(['publication:read'])]
    private ?string $venue = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['publication:read'])]
    private ?string $type = null;

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['publication:read'])]
    private ?string $license = null;

    #[ORM\Column(length: 16, enumType: OaStatus::class)]
    #[Groups(['publication:read'])]
    private OaStatus $oaStatus = OaStatus::Unknown;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['publication:read'])]
    private ?string $oaUrl = null;

    #[ORM\Column]
    #[Groups(['publication:read'])]
    private bool $fulltextAvailable = false;

    /** Full-text effectivement conservé (vrai seulement si la licence l'autorise). */
    #[ORM\Column]
    private bool $fulltextStored = false;

    #[ORM\Column(length: 16, enumType: ProcessingStatus::class)]
    private ProcessingStatus $processingStatus = ProcessingStatus::ToProcess;

    /** Statut de rétractation / mise en garde (Retraction Watch / Crossref). */
    #[ORM\Column(length: 16, enumType: RetractionStatus::class)]
    #[Groups(['publication:read'])]
    private RetractionStatus $retractionStatus = RetractionStatus::None;

    /** Date de la dernière vérification de rétractation ; null si jamais vérifiée. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retractionCheckedAt = null;

    /** Date de la dernière résolution OA (Unpaywall) ; null si jamais résolue. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $oaResolvedAt = null;

    /** Embedding du contenu (titre + résumé) pour la suggestion de placement. */
    #[ORM\Column(type: 'vector', length: 384, nullable: true)]
    private ?Vector $embedding = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int,Authorship> */
    #[ORM\OneToMany(targetEntity: Authorship::class, mappedBy: 'publication', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $authorships;

    /** @var Collection<int,PublicationProvenance> */
    #[ORM\OneToMany(targetEntity: PublicationProvenance::class, mappedBy: 'publication', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $provenances;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->authorships = new ArrayCollection();
        $this->provenances = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDoi(): ?string
    {
        return $this->doi;
    }

    public function setDoi(?string $doi): self
    {
        $this->doi = $doi;

        return $this;
    }

    /** @return array<string,string> */
    public function getExternalIds(): array
    {
        return $this->externalIds;
    }

    /** @param array<string,string> $externalIds */
    public function setExternalIds(array $externalIds): self
    {
        $this->externalIds = $externalIds;

        return $this;
    }

    public function addExternalId(string $key, string $value): self
    {
        $this->externalIds[$key] = $value;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getAbstract(): ?string
    {
        return $this->abstract;
    }

    public function setAbstract(?string $abstract): self
    {
        $this->abstract = $abstract;

        return $this;
    }

    public function getPublicationDate(): ?\DateTimeImmutable
    {
        return $this->publicationDate;
    }

    public function setPublicationDate(?\DateTimeImmutable $publicationDate): self
    {
        $this->publicationDate = $publicationDate;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function getVenue(): ?string
    {
        return $this->venue;
    }

    public function setVenue(?string $venue): self
    {
        // Certains noms de revue OpenAlex dépassent la colonne VARCHAR(512) ;
        // on tronque pour ne pas casser la moisson (cf. bug « value too long »).
        $this->venue = null !== $venue ? mb_substr($venue, 0, 512) : null;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getLicense(): ?string
    {
        return $this->license;
    }

    public function setLicense(?string $license): self
    {
        $this->license = $license;

        return $this;
    }

    public function getOaStatus(): OaStatus
    {
        return $this->oaStatus;
    }

    public function setOaStatus(OaStatus $oaStatus): self
    {
        $this->oaStatus = $oaStatus;

        return $this;
    }

    public function getOaUrl(): ?string
    {
        return $this->oaUrl;
    }

    public function setOaUrl(?string $oaUrl): self
    {
        $this->oaUrl = $oaUrl;

        return $this;
    }

    public function isFulltextAvailable(): bool
    {
        return $this->fulltextAvailable;
    }

    public function setFulltextAvailable(bool $fulltextAvailable): self
    {
        $this->fulltextAvailable = $fulltextAvailable;

        return $this;
    }

    public function isFulltextStored(): bool
    {
        return $this->fulltextStored;
    }

    public function setFulltextStored(bool $fulltextStored): self
    {
        $this->fulltextStored = $fulltextStored;

        return $this;
    }

    public function getRetractionStatus(): RetractionStatus
    {
        return $this->retractionStatus;
    }

    public function setRetractionStatus(RetractionStatus $status): self
    {
        $this->retractionStatus = $status;

        return $this;
    }

    public function getRetractionCheckedAt(): ?\DateTimeImmutable
    {
        return $this->retractionCheckedAt;
    }

    public function setRetractionCheckedAt(?\DateTimeImmutable $at): self
    {
        $this->retractionCheckedAt = $at;

        return $this;
    }

    public function getProcessingStatus(): ProcessingStatus
    {
        return $this->processingStatus;
    }

    public function setProcessingStatus(ProcessingStatus $processingStatus): self
    {
        $this->processingStatus = $processingStatus;

        return $this;
    }

    public function getOaResolvedAt(): ?\DateTimeImmutable
    {
        return $this->oaResolvedAt;
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

    public function markOaResolved(): self
    {
        $this->oaResolvedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Auteurs (forme légère pour l'API), ordonnés par rang.
     *
     * @return list<array{name:string,orcid:?string,position:int}>
     */
    #[Groups(['publication:read'])]
    public function getAuthors(): array
    {
        $authors = [];
        foreach ($this->authorships as $authorship) {
            $author = $authorship->getAuthor();
            $authors[] = [
                'name' => $author->getName(),
                'orcid' => $author->getOrcid(),
                'position' => $authorship->getPosition(),
            ];
        }

        return $authors;
    }

    /** @return Collection<int,Authorship> */
    public function getAuthorships(): Collection
    {
        return $this->authorships;
    }

    public function addAuthorship(Authorship $authorship): self
    {
        if (!$this->authorships->contains($authorship)) {
            $this->authorships->add($authorship);
            $authorship->setPublication($this);
        }

        return $this;
    }

    /** @return Collection<int,PublicationProvenance> */
    public function getProvenances(): Collection
    {
        return $this->provenances;
    }

    public function addProvenance(PublicationProvenance $provenance): self
    {
        if (!$this->provenances->contains($provenance)) {
            $this->provenances->add($provenance);
            $provenance->setPublication($this);
        }

        return $this;
    }

    public function hasProvenanceFrom(Source $source): bool
    {
        foreach ($this->provenances as $provenance) {
            if ($provenance->getSource() === $source) {
                return true;
            }
        }

        return false;
    }
}
