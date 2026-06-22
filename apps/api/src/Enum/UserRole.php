<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Rôles applicatifs (cf. spec §4/§9.4). La hiérarchie est définie dans
 * security.yaml (ADMIN > MODERATEUR/COMITE > REDACTEUR > USER).
 */
enum UserRole: string
{
    case User = 'ROLE_USER';
    case Auteur = 'ROLE_AUTEUR';
    case Researcher = 'ROLE_RESEARCHER';
    case Redacteur = 'ROLE_REDACTEUR';
    case Comite = 'ROLE_COMITE';
    case Moderateur = 'ROLE_MODERATEUR';
    case Admin = 'ROLE_ADMIN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
