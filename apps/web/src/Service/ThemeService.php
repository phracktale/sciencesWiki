<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Thème visuel courant du front public ('legacy' | 'crt'), piloté depuis le
 * back-office (réglage site.theme côté API). Lecture mise en cache courte pour
 * éviter un appel API à chaque page ; le back-office invalide le cache à
 * l'enregistrement (forget()). Exposé aux templates via le global Twig site_theme.
 */
final class ThemeService
{
    private const KEY = 'site_theme';
    private const KEY_FRAMED = 'site_framed';
    private const TTL = 60; // secondes

    public function __construct(
        private readonly ApiClient $api,
        private readonly CacheInterface $cache,
    ) {
    }

    /** Thème effectif à servir ('legacy' par défaut / en cas d'erreur). */
    public function current(): string
    {
        try {
            return $this->cache->get(self::KEY, function (ItemInterface $item): string {
                $item->expiresAfter(self::TTL);

                return $this->api->publicTheme();
            });
        } catch (\Throwable) {
            return 'legacy';
        }
    }

    /** Mode fenêtré (cadre « terminal ») actif ('true' par défaut / en cas d'erreur). */
    public function framed(): bool
    {
        try {
            return $this->cache->get(self::KEY_FRAMED, function (ItemInterface $item): bool {
                $item->expiresAfter(self::TTL);

                return $this->api->publicFramed();
            });
        } catch (\Throwable) {
            return true;
        }
    }

    /** Invalide le cache (appelé après une modification du thème en back-office). */
    public function forget(): void
    {
        try {
            $this->cache->delete(self::KEY);
            $this->cache->delete(self::KEY_FRAMED);
        } catch (\Throwable) {
            // sans gravité : le cache expirera de lui-même (TTL).
        }
    }
}
