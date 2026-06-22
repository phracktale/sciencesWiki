<?php

declare(strict_types=1);

namespace App\Harvester\Dto;

use App\Enum\OaStatus;

/**
 * Publication brute normalisable, indépendante de la source d'origine.
 *
 * Produite par un {@see \App\Harvester\Connector\SourceConnector} puis transmise
 * à l'importeur qui la dédoublonne et la persiste (cf. spec §6.2, étapes B–E).
 */
final class RawPublication
{
    /**
     * @param array<string,string> $externalIds  ex. ['openalex' => 'W123', 'arxiv' => '2401.00001']
     * @param list<RawAuthor>       $authors
     */
    public function __construct(
        public readonly string $sourceCode,
        public readonly string $idInSource,
        public readonly ?string $doi,
        public readonly string $title,
        public readonly array $externalIds = [],
        public readonly ?string $abstract = null,
        public readonly ?\DateTimeImmutable $publicationDate = null,
        public readonly ?string $language = null,
        public readonly ?string $venue = null,
        public readonly ?string $type = null,
        public readonly ?string $license = null,
        public readonly OaStatus $oaStatus = OaStatus::Unknown,
        public readonly ?string $oaUrl = null,
        public readonly ?string $landingPageUrl = null,
        public readonly bool $fulltextAvailable = false,
        public readonly array $authors = [],
        public readonly ?RawSource $source = null,
        public readonly int $citedByCount = 0,
        public readonly ?float $fwci = null,
        public readonly ?string $typeCrossref = null,
        public readonly int $referencedWorksCount = 0,
        public readonly bool $hasPdf = false,
        public readonly bool $hasGrobidXml = false,
        public readonly bool $anyRepoFulltext = false,
    ) {
    }
}
