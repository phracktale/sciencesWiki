<?php

declare(strict_types=1);

namespace App\Harvester\Message;

/**
 * Demande de résolution d'accès ouvert (Unpaywall) pour une publication
 * (cf. Phase 1 §4, étape C).
 */
final class ResolveOpenAccess
{
    public function __construct(public readonly int $publicationId)
    {
    }
}
