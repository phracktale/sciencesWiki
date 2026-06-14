<?php

declare(strict_types=1);

namespace App\Harvester\Pipeline;

use App\Entity\Publication;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Support\Doi;

/**
 * Recherche une publication déjà connue correspondant à une publication brute,
 * d'abord par DOI normalisé, puis par identifiant externe (cf. Phase 1 §6.2,
 * étape D).
 */
final class Deduplicator
{
    public function __construct(private readonly PublicationLookup $lookup)
    {
    }

    public function findExisting(RawPublication $raw): ?Publication
    {
        $doi = Doi::normalize($raw->doi);
        if (null !== $doi) {
            $existing = $this->lookup->findOneByDoi($doi);
            if (null !== $existing) {
                return $existing;
            }
        }

        foreach ($raw->externalIds as $key => $value) {
            if ('' === $value) {
                continue;
            }
            $existing = $this->lookup->findOneByExternalId($key, $value);
            if (null !== $existing) {
                return $existing;
            }
        }

        return null;
    }
}
