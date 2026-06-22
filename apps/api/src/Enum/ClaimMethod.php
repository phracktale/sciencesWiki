<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type d'étude (hiérarchie de preuve) d'une assertion
 * (cf. docs/spec-controverses-lacunes.md §4.2).
 */
enum ClaimMethod: string
{
    case MetaAnalysis = 'meta_analysis';
    case Rct = 'rct';
    case Cohort = 'cohort';
    case CaseControl = 'case_control';
    case Observational = 'observational';
    case InVivo = 'in_vivo';
    case InVitro = 'in_vitro';
    case Modeling = 'modeling';
    case Review = 'review';
    case Other = 'other';
}
