<?php

declare(strict_types=1);

namespace App\Harvester\Pipeline;

use App\Entity\Author;
use App\Entity\Authorship;
use App\Entity\Publication;
use App\Entity\PublicationProvenance;
use App\Entity\Source;
use App\Enum\ProcessingStatus;
use App\Harvester\Dto\RawAuthor;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Support\Doi;
use App\Repository\AuthorRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Normalise et persiste une publication brute : dédoublonnage, fusion des
 * provenances, application du portier de licence (cf. Phase 1 §6.2, étapes D–E).
 *
 * Idempotent : ré-importer la même publication fusionne les données au lieu de
 * créer un doublon.
 */
final class PublicationImporter
{
    /** @var array<string,Author> cache d'auteurs au sein d'une même exécution */
    private array $authorCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Deduplicator $deduplicator,
        private readonly AuthorRepository $authors,
        private readonly LicenseGate $licenseGate,
    ) {
    }

    public function import(RawPublication $raw, Source $source): ImportResult
    {
        $existing = $this->deduplicator->findExisting($raw);
        $created = null === $existing;

        $publication = $existing ?? new Publication($raw->title);

        $this->mergeMetadata($publication, $raw, $created);
        $this->ensureProvenance($publication, $raw, $source);

        if ($created) {
            $this->attachAuthors($publication, $raw->authors);
        }

        $publication->setProcessingStatus(ProcessingStatus::Normalized);
        $publication->touch();

        $this->em->persist($publication);

        return new ImportResult($publication, $created);
    }

    private function mergeMetadata(Publication $publication, RawPublication $raw, bool $created): void
    {
        $doi = Doi::normalize($raw->doi);
        if (null !== $doi && null === $publication->getDoi()) {
            $publication->setDoi($doi);
        }

        // Fusion des identifiants externes (provenances multiples).
        $ids = $publication->getExternalIds();
        foreach ($raw->externalIds as $key => $value) {
            if ('' !== $value) {
                $ids[$key] = $value;
            }
        }
        $publication->setExternalIds($ids);

        // Sur une publication existante, on ne complète que les champs manquants.
        if ($created || '' === $publication->getTitle()) {
            $publication->setTitle($raw->title);
        }
        $this->fillIfEmpty($publication, $raw);

        // Statut OA et licence : on retient la version la plus informative.
        if ($created || $publication->getOaStatus()->value === 'unknown') {
            $publication->setOaStatus($raw->oaStatus);
        }
        if (null === $publication->getLicense() && null !== $raw->license) {
            $publication->setLicense($raw->license);
        }
        if (null === $publication->getOaUrl() && null !== $raw->oaUrl) {
            $publication->setOaUrl($raw->oaUrl);
        }
        if ($raw->fulltextAvailable) {
            $publication->setFulltextAvailable(true);
        }

        // Portier de licence : stockage du full-text seulement si la licence l'autorise.
        $publication->setFulltextStored(
            $publication->isFulltextAvailable()
            && $this->licenseGate->mayStoreFullText($publication->getLicense())
        );
    }

    private function fillIfEmpty(Publication $publication, RawPublication $raw): void
    {
        if (null === $publication->getAbstract() && null !== $raw->abstract) {
            $publication->setAbstract($raw->abstract);
        }
        if (null === $publication->getPublicationDate() && null !== $raw->publicationDate) {
            $publication->setPublicationDate($raw->publicationDate);
        }
        if (null === $publication->getLanguage() && null !== $raw->language) {
            $publication->setLanguage($raw->language);
        }
        if (null === $publication->getVenue() && null !== $raw->venue) {
            $publication->setVenue($raw->venue);
        }
        if (null === $publication->getType() && null !== $raw->type) {
            $publication->setType($raw->type);
        }
    }

    private function ensureProvenance(Publication $publication, RawPublication $raw, Source $source): void
    {
        if ($publication->hasProvenanceFrom($source)) {
            return;
        }

        $publication->addProvenance(
            new PublicationProvenance($source, $raw->idInSource, $raw->license)
        );
    }

    /**
     * @param list<RawAuthor> $rawAuthors
     */
    private function attachAuthors(Publication $publication, array $rawAuthors): void
    {
        foreach ($rawAuthors as $rawAuthor) {
            $author = $this->resolveAuthor($rawAuthor);
            $publication->addAuthorship(new Authorship($author, $rawAuthor->position));
        }
    }

    private function resolveAuthor(RawAuthor $rawAuthor): Author
    {
        $cacheKey = $rawAuthor->orcid ?? 'name:'.$rawAuthor->name;
        if (isset($this->authorCache[$cacheKey])) {
            return $this->authorCache[$cacheKey];
        }

        $author = $this->authors->findOneByOrcidOrName($rawAuthor->orcid, $rawAuthor->name);
        if (null === $author) {
            $author = new Author($rawAuthor->name);
            $author->setOrcid($rawAuthor->orcid);
            $author->setAffiliation($rawAuthor->affiliation);
            $this->em->persist($author);
        }

        return $this->authorCache[$cacheKey] = $author;
    }
}
