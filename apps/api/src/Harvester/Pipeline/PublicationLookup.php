<?php

declare(strict_types=1);

namespace App\Harvester\Pipeline;

use App\Entity\Publication;

/**
 * Recherche de publications existantes, pour le dédoublonnage.
 *
 * Abstraite afin que le {@see Deduplicator} soit testable sans base de données.
 */
interface PublicationLookup
{
    public function findOneByDoi(string $doi): ?Publication;

    public function findOneByExternalId(string $key, string $value): ?Publication;
}
