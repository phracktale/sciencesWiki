<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism\Message;

/**
 * Demande de détection de doublons/plagiat pour une publication déjà empreintée
 * (cf. docs/spec-plagiat.md §7). Détection incrémentale : le nouvel arrivant est
 * comparé à l'existant.
 */
final class ScanPublication
{
    public function __construct(public readonly int $publicationId)
    {
    }
}
