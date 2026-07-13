<?php

declare(strict_types=1);

namespace Analyses\Framework\Axis;

enum AxisApplicability: string
{
    case Applicable = 'applicable';        // étude transversale → grille exécutée
    case NotApplicable = 'not_applicable'; // autre design → AXIS hors-sujet
    case Uncertain = 'uncertain';          // design ambigu → grille en basse confiance
}
