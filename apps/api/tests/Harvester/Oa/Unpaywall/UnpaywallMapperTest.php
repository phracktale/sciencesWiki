<?php

declare(strict_types=1);

namespace App\Tests\Harvester\Oa\Unpaywall;

use App\Enum\OaStatus;
use App\Harvester\Oa\Unpaywall\UnpaywallMapper;
use PHPUnit\Framework\TestCase;

final class UnpaywallMapperTest extends TestCase
{
    public function testMapsGreenOaRepository(): void
    {
        $res = (new UnpaywallMapper())->map([
            'is_oa' => true,
            'oa_status' => 'green',
            'best_oa_location' => [
                'url_for_pdf' => 'https://repo.example.org/paper.pdf',
                'url' => 'https://repo.example.org/paper',
                'license' => 'cc-by',
                'host_type' => 'repository',
                'version' => 'publishedVersion',
            ],
        ]);

        self::assertTrue($res->isOa);
        self::assertSame(OaStatus::Green, $res->oaStatus);
        self::assertSame('https://repo.example.org/paper.pdf', $res->bestOaUrl);
        self::assertSame('cc-by', $res->license);
        self::assertSame('repository', $res->hostType);
        self::assertSame('publishedVersion', $res->version);
    }

    public function testFallsBackToUrlWhenNoPdf(): void
    {
        $res = (new UnpaywallMapper())->map([
            'is_oa' => true,
            'oa_status' => 'gold',
            'best_oa_location' => ['url' => 'https://journal.example.org/article'],
        ]);

        self::assertSame('https://journal.example.org/article', $res->bestOaUrl);
        self::assertNull($res->license);
    }

    public function testMapsClosedAccess(): void
    {
        $res = (new UnpaywallMapper())->map([
            'is_oa' => false,
            'oa_status' => 'closed',
            'best_oa_location' => null,
        ]);

        self::assertFalse($res->isOa);
        self::assertSame(OaStatus::Closed, $res->oaStatus);
        self::assertNull($res->bestOaUrl);
    }
}
