<?php

declare(strict_types=1);

namespace App\Harvester\Connector;

use App\Harvester\Dto\DiscoveryCursor;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Dto\RawRef;

/**
 * Contrat commun à tous les connecteurs de source (cf. Phase 1 §3.1).
 *
 * Tous les connecteurs doivent respecter le rate-limit et l'identification
 * (User-Agent + mailto) propres à leur source.
 */
interface SourceConnector
{
    /** Code de la source (« openalex », « unpaywall », « arxiv »…). */
    public function code(): string;

    /**
     * Itère les références de travaux à traiter (pagination incrémentale).
     *
     * @return iterable<RawRef>
     */
    public function discover(DiscoveryCursor $cursor): iterable;

    /** Métadonnées normalisables d'un travail. */
    public function fetchMetadata(RawRef $ref): RawPublication;

    /**
     * Curseur atteint après la dernière page lue, pour reprendre la moisson
     * incrémentale (cursor paging OpenAlex, resumptionToken OAI-PMH…).
     */
    public function getLastCursor(): ?string;
}
