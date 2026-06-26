<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut de revue d'un rapprochement de contenu (cf. spec-plagiat.md §4.3). Tranché
 * par le comité ; rien n'est affiché publiquement tant que non « Confirmed ».
 */
enum FindingStatus: string
{
    case Unreviewed = 'unreviewed';
    case Confirmed = 'confirmed';    // plagiat/doublon avéré (comité)
    case Dismissed = 'dismissed';    // faux positif
    case Legitimate = 'legitimate';  // recouvrement justifié (méthode standard, corpus partagé…)

    public function label(): string
    {
        return match ($this) {
            self::Unreviewed => 'À examiner',
            self::Confirmed => 'Confirmé',
            self::Dismissed => 'Écarté',
            self::Legitimate => 'Recouvrement légitime',
        };
    }
}
