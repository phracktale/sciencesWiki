<?php

declare(strict_types=1);

namespace App\Harvester\Message;

use App\Harvester\Dto\RawRef;

/**
 * Demande de traitement asynchrone d'un travail découvert (cf. Phase 1 §4).
 *
 * Permet de découpler la découverte du traitement (récupération + normalisation
 * + dédoublonnage + persistance) via Symfony Messenger.
 */
final class ProcessWork
{
    public function __construct(public readonly RawRef $ref)
    {
    }
}
