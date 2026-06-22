<?php

declare(strict_types=1);

namespace App\Harvester\Message;

/**
 * Demande de moisson ciblée d'une rubrique (par son concept OpenAlex). Traitée
 * en asynchrone par le worker : découverte filtrée → import dédupliqué →
 * embeddings → placement (cf. moisson par rubrique depuis le back-office).
 */
final class HarvestRubric
{
    public function __construct(public readonly int $nodeId)
    {
    }
}
