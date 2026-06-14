<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut de validation d'une réponse (cf. spec §8.4). Le label « validé » exige
 * une relecture comité ; « non relu » est public avec bandeau.
 */
enum AnswerValidationStatus: string
{
    case Unreviewed = 'non_relu';
    case InCommitteeReview = 'en_relecture_comite';
    case Validated = 'valide';
}
