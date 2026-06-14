<?php

declare(strict_types=1);

namespace App\Enum;

/** Origine d'une question (cf. spec §8.2). */
enum QuestionOrigin: string
{
    case SuggeredByAi = 'suggeree_ia';
    case FreeUser = 'libre_utilisateur';
}
