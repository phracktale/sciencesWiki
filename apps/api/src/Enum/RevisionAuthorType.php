<?php

declare(strict_types=1);

namespace App\Enum;

/** Type d'auteur d'une révision (cf. spec §8.6). */
enum RevisionAuthorType: string
{
    case Ai = 'ia';
    case Committee = 'comite';
    case Contributor = 'contributeur';
}
