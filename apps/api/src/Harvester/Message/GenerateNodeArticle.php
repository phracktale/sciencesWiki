<?php

declare(strict_types=1);

namespace App\Harvester\Message;

/**
 * Demande de (re)génération de l'article wiki d'une rubrique, à la demande (bouton
 * admin du wiki public). Traité en asynchrone : la rédaction IA est longue, on ne
 * bloque ni la requête ni le proxy.
 */
final class GenerateNodeArticle
{
    public function __construct(public readonly int $nodeId)
    {
    }
}
