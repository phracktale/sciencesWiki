<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism\Message;

/**
 * Demande de calcul des empreintes MinHash/LSH d'une publication, puis (optionnel)
 * de son scan plagiat — enfilé en fin d'ingestion plein texte (cf. docs/spec-plagiat.md §7).
 */
final class FingerprintPublication
{
    public function __construct(
        public readonly int $publicationId,
        public readonly bool $thenScan = true,
    ) {
    }
}
