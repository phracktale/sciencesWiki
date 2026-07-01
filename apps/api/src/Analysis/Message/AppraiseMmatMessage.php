<?php

declare(strict_types=1);

namespace App\Analysis\Message;

/**
 * Demande d'évaluation MMAT d'UNE publication, à la demande. Asynchrone (worker
 * « analysis ») : l'appel LLM est long, on ne bloque ni la requête ni le proxy.
 */
final class AppraiseMmatMessage
{
    public function __construct(public readonly int $publicationId)
    {
    }
}
