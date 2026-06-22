<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut de fiabilité d'une publication au regard des rétractations / mises en
 * garde (Retraction Watch, Crossref).
 */
enum RetractionStatus: string
{
    case None = 'none';            // rien à signaler
    case Concern = 'concern';      // Expression of Concern (mise en garde)
    case Retracted = 'retracted';  // rétractée

    /** Une publication signalée (concern/retracted) est exclue de la rédaction RAG. */
    public function isFlagged(): bool
    {
        return self::None !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Fiable',
            self::Concern => 'Mise en garde (Expression of Concern)',
            self::Retracted => 'Rétractée',
        };
    }
}
