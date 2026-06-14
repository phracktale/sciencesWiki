<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut d'accès ouvert d'une publication (cf. spec §3.2).
 */
enum OaStatus: string
{
    case Diamond = 'diamond';
    case Gold = 'gold';
    case Green = 'green';
    case Hybrid = 'hybrid';
    case Bronze = 'bronze';
    case Closed = 'closed';
    case Unknown = 'unknown';

    public static function fromApi(?string $value): self
    {
        return match (strtolower((string) $value)) {
            'diamond' => self::Diamond,
            'gold' => self::Gold,
            'green' => self::Green,
            'hybrid' => self::Hybrid,
            'bronze' => self::Bronze,
            'closed' => self::Closed,
            default => self::Unknown,
        };
    }

    /** Une version full-text est-elle, a priori, librement accessible ? */
    public function isOpenAccess(): bool
    {
        return $this !== self::Closed && $this !== self::Unknown;
    }
}
