<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Cycle de vie de l'analyse « controverses & pistes » d'un nœud
 * (cf. docs/spec-controverses-lacunes.md §0.2 / §7bis). Porté par
 * TreeNode.analysisStatus. À NE PAS confondre avec ReviewStatus, qui régit la
 * validation comité d'une controverse / piste individuelle.
 */
enum AnalysisStatus: string
{
    case NotAnalyzed = 'not_analyzed';
    case Analyzing = 'analyzing';
    case Ready = 'ready';
    case Stale = 'stale';
}
