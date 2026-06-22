<?php

declare(strict_types=1);

namespace App\Analysis\Message;

/**
 * Demande d'analyse asynchrone d'un nœud (controverses & pistes) — l'habillage
 * UI de l'orchestrateur (cf. docs/spec-controverses-lacunes.md §7bis). Déclenché
 * par le CTA « Analyser ce sujet » ou le bandeau « Ré-analyser ».
 */
final class AnalyzeNodeMessage
{
    public function __construct(
        public readonly int $nodeId,
        public readonly bool $reextract = false,
        public readonly bool $force = false,
        public readonly bool $openalex = false,
    ) {
    }
}
