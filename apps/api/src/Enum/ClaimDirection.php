<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Signe de l'effet rapporté pour une relation A→B
 * (cf. docs/spec-controverses-lacunes.md §4.2).
 */
enum ClaimDirection: string
{
    case Positive = 'positive';   // A augmente / favorise B
    case Negative = 'negative';   // A diminue / protège de B
    case Null = 'null';           // pas d'effet significatif
    case Mixed = 'mixed';         // dépend des conditions
    case Unclear = 'unclear';     // non déterminable

    /** Directions retenues pour le vote de consensus (les autres sont ignorées). */
    public function countsForVote(): bool
    {
        return match ($this) {
            self::Positive, self::Negative, self::Null => true,
            self::Mixed, self::Unclear => false,
        };
    }
}
