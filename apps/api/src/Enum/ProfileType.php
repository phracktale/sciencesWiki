<?php

declare(strict_types=1);

namespace App\Enum;

/** Type de profil d'un contributeur (cf. spec §8.6/§9.4). */
enum ProfileType: string
{
    case Scientist = 'scientifique';
    case Populariser = 'vulgarisateur';
    case Contributor = 'contributeur';
}
