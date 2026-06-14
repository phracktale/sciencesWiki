<?php

declare(strict_types=1);

namespace App\Harvester\Dto;

/**
 * Auteur brut tel que fourni par une source, avant normalisation.
 */
final class RawAuthor
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $orcid = null,
        public readonly ?string $affiliation = null,
        public readonly int $position = 0,
    ) {
    }
}
