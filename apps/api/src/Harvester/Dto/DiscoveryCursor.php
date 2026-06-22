<?php

declare(strict_types=1);

namespace App\Harvester\Dto;

/**
 * État d'avancement d'une découverte, pour la moisson incrémentale (cf. spec §6.2).
 *
 * - `since`     : ne récupérer que les travaux mis à jour depuis cette date.
 * - `cursor`    : curseur de pagination opaque (cursor paging OpenAlex, ou
 *                 resumptionToken OAI-PMH pour arXiv).
 * - `set`       : sous-ensemble OAI-PMH (ex. catégorie arXiv « cs.AI ») ; ignoré
 *                 par les sources qui ne gèrent pas la notion de set.
 * - `maxRecords`: borne de sécurité sur le nombre de travaux d'une exécution.
 */
final class DiscoveryCursor
{
    public function __construct(
        public ?\DateTimeImmutable $since = null,
        public ?string $cursor = null,
        public ?string $set = null,
        public ?int $maxRecords = null,
        // Filtre supplémentaire propre à la source (ex. OpenAlex :
        // « primary_topic.field.id:fields/31 » pour moissonner une rubrique).
        public ?string $filter = null,
        // Tri propre à la source (ex. OpenAlex : « cited_by_count:desc »).
        public ?string $sort = null,
    ) {
    }
}
