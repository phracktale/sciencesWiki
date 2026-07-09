<?php

declare(strict_types=1);

namespace App\Analysis\Message;

/**
 * Demande d'évaluation méthodologique AXIS d'UNE publication, à la demande
 * (outil des espaces recherche/pédagogie). Traitée en asynchrone (worker
 * « analysis ») : l'appel LLM dure ~1 min, on ne bloque ni la requête ni le proxy.
 * Le résultat est persisté (AxisAppraisal) ; l'UI le récupère par polling.
 */
final class AppraisePublicationMessage
{
    public function __construct(
        public readonly int $publicationId,
        // true = ré-évaluation forcée (purge l'existant et recalcule) — bouton « Refaire ».
        public readonly bool $reappraise = false,
    ) {
    }
}
