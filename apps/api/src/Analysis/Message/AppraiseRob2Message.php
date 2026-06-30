<?php

declare(strict_types=1);

namespace App\Analysis\Message;

/**
 * Demande d'évaluation RoB 2 d'UNE publication, à la demande (outil recherche/
 * pédagogie). Asynchrone (worker « analysis ») : l'appel LLM est long, on ne bloque
 * ni la requête ni le proxy. Le résultat est persisté ; l'UI le récupère par polling.
 */
final class AppraiseRob2Message
{
    public function __construct(public readonly int $publicationId)
    {
    }
}
