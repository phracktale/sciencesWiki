<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type d'API exposée par une source moissonnée.
 */
enum ApiType: string
{
    case Rest = 'rest';
    case OaiPmh = 'oai_pmh';
}
