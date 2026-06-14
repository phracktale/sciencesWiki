<?php

declare(strict_types=1);

namespace App\Tests\Harvester\Ai;

use App\Harvester\Ai\EmbeddingClient;
use App\Harvester\Ai\HashingEmbeddingClient;
use PHPUnit\Framework\TestCase;

final class HashingEmbeddingClientTest extends TestCase
{
    public function testDimensionAndNormalization(): void
    {
        $client = new HashingEmbeddingClient();
        $vector = $client->embed('open science is wonderful');

        self::assertCount(EmbeddingClient::DIMENSIONS, $vector);

        $norm = sqrt(array_sum(array_map(static fn (float $c): float => $c * $c, $vector)));
        self::assertEqualsWithDelta(1.0, $norm, 1e-9, 'le vecteur doit être normalisé L2');
    }

    public function testDeterministic(): void
    {
        $client = new HashingEmbeddingClient();

        self::assertSame($client->embed('reproducible'), $client->embed('reproducible'));
    }

    public function testDifferentTextsDiffer(): void
    {
        $client = new HashingEmbeddingClient();

        self::assertNotSame($client->embed('quantum physics'), $client->embed('molecular biology'));
    }

    public function testEmptyTextProducesUnitVector(): void
    {
        $client = new HashingEmbeddingClient();
        $vector = $client->embed('');

        $norm = sqrt(array_sum(array_map(static fn (float $c): float => $c * $c, $vector)));
        self::assertEqualsWithDelta(1.0, $norm, 1e-9);
    }
}
