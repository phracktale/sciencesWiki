<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sélectionne l'implémentation d'embedding selon l'environnement.
 *
 * - `EMBEDDING_DRIVER=http` (défaut) : service `ml/` auto-hébergé (production) ;
 * - `EMBEDDING_DRIVER=hashing`       : embedder déterministe local (dev/tests).
 */
final class EmbeddingClientFactory
{
    public function __construct(
        private readonly HttpEmbeddingClient $http,
        private readonly HashingEmbeddingClient $hashing,
        #[Autowire(env: 'EMBEDDING_DRIVER')]
        private readonly string $driver,
    ) {
    }

    public function create(): EmbeddingClient
    {
        return 'hashing' === strtolower($this->driver) ? $this->hashing : $this->http;
    }
}
