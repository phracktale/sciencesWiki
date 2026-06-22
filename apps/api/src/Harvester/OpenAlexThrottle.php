<?php

declare(strict_types=1);

namespace App\Harvester;

use App\Service\SettingsService;
use Doctrine\DBAL\Connection;

/**
 * Respect des limites de l'API OpenAlex. Les seuils sont **paramétrables** en
 * back-office (réglages openalex.per_minute / openalex.per_day) pour s'adapter au
 * polite pool (sans clé : ~10 req/s, crédits ~10000/jour) ou à un plan supérieur.
 * À appeler (tick) juste avant chaque requête OpenAlex.
 *
 * Capture aussi les en-têtes de crédit renvoyés par OpenAlex (x-ratelimit-*-usd)
 * pour afficher le crédit accordé et le coût dépensé du jour dans le suivi.
 */
final class OpenAlexThrottle
{
    private float $last = 0.0;

    public function __construct(
        private readonly Connection $conn,
        private readonly SettingsService $settings,
    ) {
    }

    public function tick(): void
    {
        // 1) Espacement des requêtes selon le taux/minute configuré.
        $perMinute = $this->settings->openalexPerMinute();
        $minInterval = 60.0 / $perMinute;

        $now = microtime(true);
        if ($this->last > 0.0) {
            $delta = $now - $this->last;
            if ($delta < $minInterval) {
                usleep((int) (($minInterval - $delta) * 1_000_000));
            }
        }
        $this->last = microtime(true);

        // 2) Plafond quotidien configuré (compteur partagé en base, par jour).
        $cap = $this->settings->openalexPerDay();
        $key = 'openalex.count.'.date('Y-m-d');
        $count = (int) $this->conn->executeQuery(
            "INSERT INTO setting (name, value) VALUES (:n, '1')
             ON CONFLICT (name) DO UPDATE SET value = (CAST(setting.value AS INTEGER) + 1)::text
             RETURNING CAST(value AS INTEGER)",
            ['n' => $key],
        )->fetchOne();

        if ($count > $cap) {
            throw new \RuntimeException(\sprintf('Plafond quotidien de requêtes OpenAlex atteint (%d, configurable en back-office). Réessayez demain ou augmentez la limite.', $cap));
        }
    }

    /**
     * Mémorise l'état de limite/crédit OpenAlex de la dernière réponse (en-têtes
     * X-RateLimit-*), pour l'afficher dans le suivi : limite quotidienne RÉELLE
     * d'OpenAlex, restant du jour, crédit USD, coût de la requête, reset.
     *
     * @param array<string,string|int|float|null> $values clés openalex.* → valeur
     */
    public function recordCredits(array $values): void
    {
        $values = array_filter($values, static fn ($v): bool => null !== $v && '' !== (string) $v);
        if ([] === $values) {
            return;
        }
        $values['openalex.credit.updated_at'] = date('c');

        foreach ($values as $name => $value) {
            $this->conn->executeStatement(
                "INSERT INTO setting (name, value) VALUES (:n, :v)
                 ON CONFLICT (name) DO UPDATE SET value = :v",
                ['n' => $name, 'v' => (string) $value],
            );
        }
    }

    /** Mémorise le nombre total de travaux OpenAlex pour une rubrique (meta.count). */
    public function recordRubricTotal(string $slug, int $total): void
    {
        $this->conn->executeStatement(
            "INSERT INTO setting (name, value) VALUES (:n, :v)
             ON CONFLICT (name) DO UPDATE SET value = :v",
            ['n' => 'openalex.total.'.$slug, 'v' => (string) $total],
        );
    }
}
