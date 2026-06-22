<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Flux de validation comité d'une controverse / piste détectée
 * (cf. docs/spec-controverses-lacunes.md §4.2, y nommé « AnalysisStatus » —
 * renommé ReviewStatus pour le distinguer du cycle de vie du nœud
 * {@see AnalysisStatus}). Rien n'est publié sans passage comité, comme les
 * réponses RAG.
 */
enum ReviewStatus: string
{
    case Detected = 'detected';
    case UnderReview = 'under_review';
    case Confirmed = 'confirmed';
    case Dismissed = 'dismissed';
}
