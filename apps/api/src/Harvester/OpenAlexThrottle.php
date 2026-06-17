<?php

declare(strict_types=1);

namespace App\Harvester;

use Doctrine\DBAL\Connection;

/**
 * Respect des limites de l'API OpenAlex : au plus 10 requêtes/seconde et
 * 100 000 requêtes par jour. À appeler (tick) juste avant chaque requête OpenAlex.
 */
final class OpenAlexThrottle
{
    private const MIN_INTERVAL = 0.11; // ~9 req/s, marge sous la limite de 10/s
    private const DAILY_CAP = 100000;

    private float $last = 0.0;

    public function __construct(private readonly Connection $conn)
    {
    }

    public function tick(): void
    {
        // 1) Espacement des requêtes (≤ 10/s).
        $now = microtime(true);
        if ($this->last > 0.0) {
            $delta = $now - $this->last;
            if ($delta < self::MIN_INTERVAL) {
                usleep((int) ((self::MIN_INTERVAL - $delta) * 1_000_000));
            }
        }
        $this->last = microtime(true);

        // 2) Plafond quotidien (compteur partagé en base, par jour).
        $key = 'openalex.count.'.date('Y-m-d');
        $count = (int) $this->conn->executeQuery(
            "INSERT INTO setting (name, value) VALUES (:n, '1')
             ON CONFLICT (name) DO UPDATE SET value = (CAST(setting.value AS INTEGER) + 1)::text
             RETURNING CAST(value AS INTEGER)",
            ['n' => $key],
        )->fetchOne();

        if ($count > self::DAILY_CAP) {
            throw new \RuntimeException(\sprintf('Quota quotidien OpenAlex atteint (%d requêtes). Réessayez demain.', self::DAILY_CAP));
        }
    }
}
