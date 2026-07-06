<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Réponse à un item de la grille AXIS (cf. docs/spec-axis-articles.md §2). Reprend
 * les trois modalités de l'outil original : « Yes / No / Don't know ». Le « Don't
 * know » (Unclear) est une réponse VALIDE — fréquente quand l'information manque
 * dans le texte source (résumé seul), pas un échec.
 */
enum AxisAnswer: string
{
    case Yes = 'yes';
    case Partial = 'partial';   // partiellement / oui avec réserve
    case No = 'no';
    case Na = 'na';             // non applicable à cette étude (item hors-sujet)
    case Unclear = 'unclear';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Oui',
            self::Partial => 'Partiellement',
            self::No => 'Non',
            self::Na => 'Non applicable',
            self::Unclear => 'Indéterminé',
        };
    }
}
