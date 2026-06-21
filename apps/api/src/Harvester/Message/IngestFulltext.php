<?php

declare(strict_types=1);

namespace App\Harvester\Message;

/**
 * Demande d'ingestion du texte intégral d'UNE publication (téléchargement du PDF
 * éditeur → GROBID → fragments → embeddings). Traitée en parallèle par un pool de
 * workers dédiés (transport « fulltext »), pour accélérer la vectorisation
 * intégrale du corpus en accès libre sans bloquer la moisson.
 */
final class IngestFulltext
{
    public function __construct(public readonly int $publicationId)
    {
    }
}
