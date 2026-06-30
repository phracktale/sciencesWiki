<?php

declare(strict_types=1);

namespace App\Analysis\Message;

/**
 * Demande de détection du devis d'une publication (→ outils d'évaluation critique
 * applicables). Asynchrone (worker « analysis ») : un appel LLM léger, on ne bloque
 * pas la recherche. Le résultat est persisté (study_design / appraisal_tools).
 */
final class ClassifyStudyMessage
{
    public function __construct(public readonly int $publicationId)
    {
    }
}
