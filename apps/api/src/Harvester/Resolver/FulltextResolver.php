<?php

declare(strict_types=1);

namespace App\Harvester\Resolver;

use App\Entity\Publication;

/**
 * Résolveur de texte intégral en accès libre : à partir d'une publication (DOI/PMID),
 * trouve l'URL d'un PDF OA LÉGAL (dépôt/éditeur), à ingérer par le pipeline existant
 * (téléchargement → GROBID → fragments). Sert de REPLI quand OpenAlex n'a pas d'oa_url.
 *
 * Implémentations taguées « app.fulltext_resolver » (cf. services.yaml) et essayées
 * dans l'ordre par {@see App\Harvester\Ai\FulltextIngester}.
 */
interface FulltextResolver
{
    /** Code de provenance (ex. « core », « europe_pmc »). */
    public function source(): string;

    /** URL d'un PDF OA pour cette publication, ou null si introuvable/désactivé. */
    public function resolvePdfUrl(Publication $publication): ?string;
}
