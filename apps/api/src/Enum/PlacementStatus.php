<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut d'une suggestion de placement (validation humaine — cf. spec §6.3).
 */
enum PlacementStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
