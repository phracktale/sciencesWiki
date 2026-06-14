<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Étape de traitement d'une publication dans le pipeline de moisson (cf. spec §6.2).
 */
enum ProcessingStatus: string
{
    case ToProcess = 'to_process';
    case Normalized = 'normalized';
    case Enriched = 'enriched';
    case InValidation = 'in_validation';
    case Placed = 'placed';
    case Rejected = 'rejected';
}
