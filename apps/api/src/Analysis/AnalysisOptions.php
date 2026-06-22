<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * Options d'un run d'analyse (cf. docs/spec-controverses-lacunes.md §7).
 */
final class AnalysisOptions
{
    public function __construct(
        /** Ré-extrait les claims des publications déjà traitées (sinon incrémental). */
        public readonly bool $reextract = false,
        /** Force le run même si le nœud est déjà en cours d'analyse (lève le verrou). */
        public readonly bool $force = false,
        /** Élargit la vérification croisée à OpenAlex (Phase B ; par TERMES, pas par date). */
        public readonly bool $openalex = false,
        /** Plafond de publications traitées par run. */
        public readonly int $limit = 1000,
        /** Distance cosinus de fusion des axes (controverses). */
        public readonly float $theta = 0.15,
    ) {
    }
}
