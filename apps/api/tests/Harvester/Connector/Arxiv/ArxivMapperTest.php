<?php

declare(strict_types=1);

namespace App\Tests\Harvester\Connector\Arxiv;

use App\Enum\OaStatus;
use App\Harvester\Connector\Arxiv\ArxivMapper;
use PHPUnit\Framework\TestCase;

final class ArxivMapperTest extends TestCase
{
    public function testMapPreprintWithCcBy(): void
    {
        $pub = (new ArxivMapper())->map([
            'id' => '2401.01234',
            'created' => '2024-01-05',
            'doi' => null,
            'title' => "On  the   nature\n of things",
            'abstract' => "  We explore\n  open science.  ",
            'categories' => 'cs.AI cs.LG',
            'license' => 'http://creativecommons.org/licenses/by/4.0/',
            'journal_ref' => null,
            'authors' => [
                ['keyname' => 'Doe', 'forenames' => 'Jane', 'affiliation' => 'MIT'],
                ['keyname' => 'Smith', 'forenames' => 'John', 'affiliation' => null],
            ],
        ]);

        self::assertSame('arxiv', $pub->sourceCode);
        self::assertSame('2401.01234', $pub->idInSource);
        self::assertNull($pub->doi);
        self::assertSame('On the nature of things', $pub->title);
        self::assertSame('We explore open science.', $pub->abstract);
        self::assertSame('2024-01-05', $pub->publicationDate?->format('Y-m-d'));
        self::assertSame('preprint', $pub->type);
        self::assertSame('arXiv', $pub->venue);
        self::assertSame('cc-by', $pub->license);
        self::assertSame(OaStatus::Green, $pub->oaStatus);
        self::assertSame('https://arxiv.org/abs/2401.01234', $pub->oaUrl);
        self::assertTrue($pub->fulltextAvailable);
        self::assertSame('2401.01234', $pub->externalIds['arxiv']);

        self::assertCount(2, $pub->authors);
        self::assertSame('Jane Doe', $pub->authors[0]->name);
        self::assertSame('MIT', $pub->authors[0]->affiliation);
        self::assertSame(0, $pub->authors[0]->position);
        self::assertSame('John Smith', $pub->authors[1]->name);
    }

    public function testJournalRefBecomesVenue(): void
    {
        $pub = (new ArxivMapper())->map([
            'id' => '1234.5678',
            'created' => '2020-03-03',
            'journal_ref' => 'Phys. Rev. Lett. 123, 456 (2020)',
            'title' => 'A title',
            'abstract' => 'An abstract',
            'authors' => [],
        ]);

        self::assertSame('Phys. Rev. Lett. 123, 456 (2020)', $pub->venue);
    }
}
