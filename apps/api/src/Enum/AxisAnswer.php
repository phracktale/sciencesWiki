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
    case No = 'no';
    case Unclear = 'unclear';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Oui',
            self::No => 'Non',
            self::Unclear => 'Indéterminé',
        };
    }
}
