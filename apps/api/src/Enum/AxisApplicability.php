<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Verrou d'applicabilité d'AXIS (cf. docs/spec-axis-articles.md §3). L'outil n'a
 * été conçu et validé QUE pour les études transversales (cross-sectional) : un
 * article d'un autre design (RCT, méta-analyse, in vitro…) est marqué
 * « NotApplicable » et la grille n'est PAS exécutée (résultat trompeur évité).
 */
enum AxisApplicability: string
{
    case Applicable = 'applicable';       // étude transversale → grille exécutée
    case NotApplicable = 'not_applicable'; // autre design → AXIS hors-sujet
    case Uncertain = 'uncertain';          // design ambigu → grille en basse confiance

    public function label(): string
    {
        return match ($this) {
            self::Applicable => 'Applicable (étude transversale)',
            self::NotApplicable => 'Non applicable (autre type d’étude)',
            self::Uncertain => 'Applicabilité incertaine',
        };
    }
}
