<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Embedder de production : appelle le service auto-hébergé `ml/` (sentence-
 * transformers) exposant `POST /embed` → `{ "embedding": [...] }`.
 */
final class HttpEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'ML_EMBED_URL')]
        private readonly string $embedUrl,
    ) {
    }

    public function embed(string $text): array
    {
        $data = $this->httpClient->request('POST', $this->embedUrl, [
            'json' => ['text' => $text],
            'timeout' => 30,
        ])->toArray();

        $embedding = $data['embedding'] ?? null;
        if (!\is_array($embedding) || \count($embedding) !== self::DIMENSIONS) {
            throw new \RuntimeException(\sprintf('Réponse d\'embedding invalide (dimension attendue : %d).', self::DIMENSIONS));
        }

        return array_map(static fn ($v): float => (float) $v, array_values($embedding));
    }

    public function dimensions(): int
    {
        return self::DIMENSIONS;
    }
}
