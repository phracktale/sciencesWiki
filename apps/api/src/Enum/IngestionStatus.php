<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * État d'une exécution de moisson (IngestionJob).
 */
enum IngestionStatus: string
{
    case Running = 'running';
    case Ok = 'ok';
    case Partial = 'partial';
    case Failed = 'failed';
}
