<?php

declare(strict_types=1);

namespace App\Enum;

/** Nature d'une réponse (cf. spec §8.4). */
enum AnswerType: string
{
    case Canonical = 'canonique';
    case Free = 'libre';
}
