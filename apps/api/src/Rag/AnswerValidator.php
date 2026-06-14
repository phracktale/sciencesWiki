<?php

declare(strict_types=1);

namespace App\Rag;

use App\Entity\Answer;
use App\Entity\User;
use App\Enum\AnswerValidationStatus;

/**
 * Transitions de validation d'une réponse par le comité (cf. spec §8.4).
 *
 * La validation est le **mur de publication** : seul un passage par ici donne le
 * label « validé » et autorise le bloc académique. (Le rattachement à un membre
 * de comité identifié viendra avec le domaine « gouvernance ».)
 */
final class AnswerValidator
{
    public function validate(Answer $answer, ?User $reviewer = null): void
    {
        $answer
            ->setValidationStatus(AnswerValidationStatus::Validated)
            ->markValidatedByCommittee($reviewer);
    }

    public function sendBackToReview(Answer $answer): void
    {
        $answer->setValidationStatus(AnswerValidationStatus::InCommitteeReview);
    }
}
