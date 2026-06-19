<?php

declare(strict_types=1);

namespace App\Harvester\Pipeline;

use App\Entity\Author;
use App\Entity\Authorship;
use App\Entity\Journal;
use App\Entity\Publication;
use App\Entity\PublicationProvenance;
use App\Entity\Publisher;
use App\Entity\Source;
use App\Enum\ProcessingStatus;
use App\Harvester\Dto\RawAuthor;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Dto\RawSource;
use App\Harvester\Support\Doi;
use App\Repository\AuthorRepository;
use App\Repository\JournalRepository;
use App\Repository\PublisherRepository;
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

    /** @var array<string,Journal> cache de revues au sein d'une même exécution */
    private array $journalCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Deduplicator $deduplicator,
        private readonly AuthorRepository $authors,
        private readonly LicenseGate $licenseGate,
        private readonly \Doctrine\DBAL\Connection $conn,
        private readonly JournalRepository $journals,
        private readonly PublisherRepository $publishers,
    ) {
    }

    /**
     * Réinitialise le cache d'auteurs. À appeler au début de chaque exécution :
     * dans un worker long-running, l'EntityManager est réinitialisé entre messages,
     * donc les entités mises en cache deviennent détachées — les réutiliser
     * provoquerait des ré-insertions (ex. violation de contrainte unique ORCID).
     */
    public function reset(): void
    {
        $this->authorCache = [];
        $this->journalCache = [];
    }

    public function import(RawPublication $raw, Source $source): ImportResult
    {
        $existing = $this->deduplicator->findExisting($raw);
        $created = null === $existing;

        $publication = $existing ?? new Publication($raw->title);

        $this->mergeMetadata($publication, $raw, $created);
        $this->ensureProvenance($publication, $raw, $source);

        // Revue + éditeur (référentiel enrichi au fil de la moisson).
        if (null !== $raw->source && null === $publication->getJournal()) {
            $publication->setJournal($this->resolveJournal($raw->source));
        }

        if ($created) {
            $this->attachAuthors($publication, $raw->authors);
        }

        $publication->setProcessingStatus(ProcessingStatus::Normalized);
        $publication->touch();

        $this->em->persist($publication);

        return new ImportResult($publication, $created);
    }

    /**
     * Rattrapage : complète une publication EXISTANTE avec sa revue/éditeur et son
     * lien canonique (re-fetch OpenAlex). Ne touche que les champs manquants.
     */
    public function applySourceAndLanding(Publication $publication, RawPublication $raw): bool
    {
        $changed = false;
        if (null === $publication->getLandingPageUrl() && null !== $raw->landingPageUrl) {
            $publication->setLandingPageUrl($raw->landingPageUrl);
            $changed = true;
        }
        if (null === $publication->getJournal() && null !== $raw->source) {
            $publication->setJournal($this->resolveJournal($raw->source));
            $changed = true;
        }

        return $changed;
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
        if (null === $publication->getLandingPageUrl() && null !== $raw->landingPageUrl) {
            $publication->setLandingPageUrl($raw->landingPageUrl);
        }

        // Métadonnées OpenAlex : citations/FWCI évoluent → on rafraîchit à chaque passage.
        $publication->setCitedByCount($raw->citedByCount);
        $publication->setFwci($raw->fwci);
        $publication->setReferencedWorksCount($raw->referencedWorksCount);
        $publication->setHasPdf($raw->hasPdf);
        $publication->setHasGrobidXml($raw->hasGrobidXml);
        $publication->setAnyRepoFulltext($raw->anyRepoFulltext);
        if (null === $publication->getTypeCrossref() && null !== $raw->typeCrossref) {
            $publication->setTypeCrossref($raw->typeCrossref);
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
        // Dédoublonnage par publication : un même auteur (résolu vers la même
        // entité) ne doit être rattaché qu'une fois (contrainte uniq_authorship).
        $seen = [];
        foreach ($rawAuthors as $rawAuthor) {
            $author = $this->resolveAuthor($rawAuthor);
            $key = spl_object_id($author);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $publication->addAuthorship(new Authorship($author, $rawAuthor->position));
        }
    }

    private function resolveAuthor(RawAuthor $rawAuthor): Author
    {
        $cacheKey = $rawAuthor->orcid ?? 'name:'.$rawAuthor->name;
        if (isset($this->authorCache[$cacheKey])) {
            return $this->authorCache[$cacheKey];
        }

        $orcid = $rawAuthor->orcid;

        // Concurrence (plusieurs workers en parallèle) : deux workers peuvent
        // rencontrer le MÊME auteur identifié par ORCID et tenter de l'insérer en
        // même temps → violation de la contrainte unique → EntityManager fermé →
        // job de moisson échoué. On rend l'insertion atomique côté base via un
        // upsert (ON CONFLICT DO NOTHING), puis on recharge l'entité gérée. Postgres
        // sérialise le conflit au niveau de l'index : aucune exception ne remonte.
        if (null !== $orcid && '' !== $orcid) {
            $this->conn->executeStatement(
                'INSERT INTO author (name, orcid, affiliation) VALUES (:n, :o, :a) ON CONFLICT (orcid) DO NOTHING',
                [
                    'n' => mb_substr($rawAuthor->name, 0, 500),
                    'o' => $orcid,
                    'a' => null !== $rawAuthor->affiliation ? mb_substr($rawAuthor->affiliation, 0, 500) : null,
                ],
            );
            $author = $this->authors->findOneBy(['orcid' => $orcid]);
            if (null !== $author) {
                return $this->authorCache[$cacheKey] = $author;
            }
        }

        // Sans ORCID : pas de contrainte unique (les homonymes restent distincts) ;
        // résolution ORM classique par nom.
        $author = $this->authors->findOneByOrcidOrName(null, $rawAuthor->name);
        if (null === $author) {
            $author = new Author($rawAuthor->name);
            $author->setOrcid(null);
            $author->setAffiliation($rawAuthor->affiliation);
            $this->em->persist($author);
        }

        return $this->authorCache[$cacheKey] = $author;
    }

    /**
     * Résout (ou crée) la revue, race-safe en concurrence comme pour les auteurs
     * (upsert sur openalex_id), et rattache son éditeur. Renvoie l'entité gérée.
     */
    private function resolveJournal(RawSource $src): ?Journal
    {
        if (isset($this->journalCache[$src->openAlexId])) {
            return $this->journalCache[$src->openAlexId];
        }

        $publisherId = $this->resolvePublisher($src)?->getId();

        $this->conn->executeStatement(
            'INSERT INTO journal (openalex_id, name, issn_l, type, is_oa, is_in_doaj, homepage_url, publisher_id)
             VALUES (:o, :n, :i, :t, :oa, :doaj, :h, :p) ON CONFLICT (openalex_id) DO NOTHING',
            [
                'o' => $src->openAlexId,
                'n' => $src->name,
                'i' => $src->issnL,
                't' => $src->type,
                'oa' => $src->isOa ? 'true' : 'false',
                'doaj' => $src->isInDoaj ? 'true' : 'false',
                'h' => $src->homepageUrl,
                'p' => $publisherId,
            ],
        );

        $journal = $this->journals->findOneBy(['openAlexId' => $src->openAlexId]);

        return $this->journalCache[$src->openAlexId] = $journal;
    }

    /** Résout (ou crée) l'éditeur d'une revue, race-safe (upsert sur openalex_id). */
    private function resolvePublisher(RawSource $src): ?Publisher
    {
        $name = $src->publisherName;
        if (null === $name || '' === $name) {
            return null;
        }
        $openAlexId = $src->publisherOpenAlexId;

        if (null !== $openAlexId && '' !== $openAlexId) {
            $this->conn->executeStatement(
                'INSERT INTO publisher (openalex_id, name) VALUES (:o, :n) ON CONFLICT (openalex_id) DO NOTHING',
                ['o' => $openAlexId, 'n' => $name],
            );

            return $this->publishers->findOneBy(['openAlexId' => $openAlexId]);
        }

        // Éditeur sans identifiant OpenAlex (rare) : résolution par nom.
        $publisher = $this->publishers->findOneBy(['name' => $name]);
        if (null === $publisher) {
            $publisher = new Publisher($name);
            $this->em->persist($publisher);
        }

        return $publisher;
    }
}
