<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

/**
 * Levée lorsqu'on tente d'analyser une publication dont SEUL le résumé est disponible
 * (texte intégral non conservé). Une évaluation méthodologique fiable exige le texte
 * intégral : on bloque et on renvoie les métadonnées pour guider l'utilisateur (déposer le PDF).
 */
final class AbstractOnlyException extends \RuntimeException
{
    /** @param array<string, mixed> $publication */
    public function __construct(private readonly array $publication)
    {
        parent::__construct('Seul le résumé de cette étude est disponible.');
    }

    /** @return array<string, mixed> */
    public function publication(): array
    {
        return $this->publication;
    }
}
