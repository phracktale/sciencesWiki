<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut d'une proposition d'ajout au corpus (étude déposée par un utilisateur via
 * l'évaluation critique). Tranché par le comité : l'étude reste PRIVÉE tant que non
 * « Approved ». « Approved » déclenche son intégration (embedding + placement).
 */
enum SubmissionStatus: string
{
    case Pending = 'pending';     // en attente de revue comité
    case Approved = 'approved';   // acceptée → intégrée au corpus public
    case Rejected = 'rejected';   // refusée → l'étude reste privée à l'uploadeur

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À examiner',
            self::Approved => 'Acceptée',
            self::Rejected => 'Refusée',
        };
    }
}
