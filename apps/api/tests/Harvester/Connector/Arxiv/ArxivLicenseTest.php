<?php

declare(strict_types=1);

namespace App\Tests\Harvester\Connector\Arxiv;

use App\Harvester\Connector\Arxiv\ArxivLicense;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArxivLicenseTest extends TestCase
{
    #[DataProvider('provideLicenses')]
    public function testNormalize(?string $url, ?string $expected): void
    {
        self::assertSame($expected, ArxivLicense::normalize($url));
    }

    /**
     * @return iterable<string,array{?string,?string}>
     */
    public static function provideLicenses(): iterable
    {
        yield 'cc-by' => ['http://creativecommons.org/licenses/by/4.0/', 'cc-by'];
        yield 'cc-by-sa' => ['http://creativecommons.org/licenses/by-sa/4.0/', 'cc-by-sa'];
        yield 'cc-by-nc-sa' => ['http://creativecommons.org/licenses/by-nc-sa/4.0/', 'cc-by-nc-sa'];
        yield 'cc0' => ['http://creativecommons.org/publicdomain/zero/1.0/', 'cc0'];
        yield 'arxiv nonexclusive' => ['http://arxiv.org/licenses/nonexclusive-distrib/1.0/', 'arxiv-nonexclusive'];
        yield 'null' => [null, null];
        yield 'unknown' => ['http://example.org/whatever', null];
    }
}
