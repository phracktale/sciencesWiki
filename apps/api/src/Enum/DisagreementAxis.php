<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Pourquoi deux assertions divergent : vrai désaccord vs artefact
 * (population / méthode / dose / époque) — cf.
 * docs/spec-controverses-lacunes.md §4.2 et §6.1.
 */
enum DisagreementAxis: string
{
    case Genuine = 'genuine';       // mêmes conditions, conclusions opposées
    case Population = 'population';
    case Method = 'method';
    case Dose = 'dose';
    case Temporal = 'temporal';     // une étude récente supersède
    case Unclear = 'unclear';
}
