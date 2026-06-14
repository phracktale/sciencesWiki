<?php

declare(strict_types=1);

namespace App\Harvester\Dto;

/**
 * État d'avancement d'une découverte, pour la moisson incrémentale (cf. spec §6.2).
 *
 * - `since`     : ne récupérer que les travaux mis à jour depuis cette date.
 * - `cursor`    : curseur de pagination opaque (cursor paging OpenAlex, ou
 *                 resumptionToken OAI-PMH pour arXiv).
 * - `maxRecords`: borne de sécurité sur le nombre de travaux d'une exécution.
 */
final class DiscoveryCursor
{
    public function __construct(
        public ?\DateTimeImmutable $since = null,
        public ?string $cursor = null,
        public ?int $maxRecords = null,
    ) {
    }
}
