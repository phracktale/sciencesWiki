<?php

declare(strict_types=1);

namespace App\Harvester\Dto;

/**
 * Revue/source brute (journal, dépôt, actes…) telle que décrite par
 * `primary_location.source` chez OpenAlex, avec une référence à son éditeur
 * (host_organization). Normalisée par l'importeur en entités Source + Publisher.
 */
final class RawSource
{
    public function __construct(
        public readonly string $openAlexId,
        public readonly string $name,
        public readonly ?string $issnL = null,
        public readonly ?string $type = null,
        public readonly bool $isOa = false,
        public readonly bool $isInDoaj = false,
        public readonly ?string $publisherOpenAlexId = null,
        public readonly ?string $publisherName = null,
        public readonly ?string $homepageUrl = null,
    ) {
    }
}
