<?php

declare(strict_types=1);

namespace Analyses\Message;

/**
 * Demande d'exécution asynchrone d'une analyse déjà créée (statut « queued »).
 * Tout le contexte (document, override, demandeur) est porté par l'entité Assessment.
 */
final class RunAnalysisMessage
{
    public function __construct(public readonly string $assessmentId)
    {
    }
}
