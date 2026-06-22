<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Robustesse méthodologique estimée par le LLM
 * (cf. docs/spec-controverses-lacunes.md §4.2).
 */
enum ClaimConfidence: string
{
    case High = 'high';
    case Moderate = 'moderate';
    case Low = 'low';
}
