<?php

declare(strict_types=1);

namespace App\Tests\Harvester\Pipeline;

use App\Entity\Publication;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Pipeline\Deduplicator;
use App\Harvester\Pipeline\PublicationLookup;
use PHPUnit\Framework\TestCase;

final class DeduplicatorTest extends TestCase
{
    public function testMatchesByNormalizedDoi(): void
    {
        $existing = (new Publication('Existant'))->setDoi('10.1/abc');
        $lookup = new InMemoryLookup(byDoi: ['10.1/abc' => $existing]);
        $dedup = new Deduplicator($lookup);

        // Le DOI brut (URL, majuscules) doit être normalisé avant la recherche.
        $raw = $this->rawWithDoi('https://doi.org/10.1/ABC');

        self::assertSame($existing, $dedup->findExisting($raw));
    }

    public function testMatchesByExternalIdWhenNoDoi(): void
    {
        $existing = (new Publication('Préprint'))->addExternalId('arxiv', '2401.00001');
        $lookup = new InMemoryLookup(byExternalId: ['arxiv:2401.00001' => $existing]);
        $dedup = new Deduplicator($lookup);

        $raw = new RawPublication(
            sourceCode: 'arxiv',
            idInSource: '2401.00001',
            doi: null,
            title: 'Préprint',
            externalIds: ['arxiv' => '2401.00001'],
        );

        self::assertSame($existing, $dedup->findExisting($raw));
    }

    public function testReturnsNullWhenUnknown(): void
    {
        $dedup = new Deduplicator(new InMemoryLookup());

        self::assertNull($dedup->findExisting($this->rawWithDoi('10.9/zzz')));
    }

    private function rawWithDoi(string $doi): RawPublication
    {
        return new RawPublication(
            sourceCode: 'openalex',
            idInSource: 'W1',
            doi: $doi,
            title: 'Titre',
        );
    }
}

/**
 * Implémentation en mémoire de PublicationLookup pour les tests.
 */
final class InMemoryLookup implements PublicationLookup
{
    /**
     * @param array<string,Publication> $byDoi
     * @param array<string,Publication> $byExternalId clés au format "key:value"
     */
    public function __construct(
        private readonly array $byDoi = [],
        private readonly array $byExternalId = [],
    ) {
    }

    public function findOneByDoi(string $doi): ?Publication
    {
        return $this->byDoi[$doi] ?? null;
    }

    public function findOneByExternalId(string $key, string $value): ?Publication
    {
        return $this->byExternalId[$key.':'.$value] ?? null;
    }
}
