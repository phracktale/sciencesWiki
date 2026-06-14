<?php

declare(strict_types=1);

namespace App\Tests\Harvester\Pipeline;

use App\Harvester\Pipeline\LicenseGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LicenseGateTest extends TestCase
{
    #[DataProvider('provideLicenses')]
    public function testMayStoreFullText(?string $license, bool $expected): void
    {
        self::assertSame($expected, (new LicenseGate())->mayStoreFullText($license));
    }

    /**
     * @return iterable<string,array{?string,bool}>
     */
    public static function provideLicenses(): iterable
    {
        yield 'cc0' => ['cc0', true];
        yield 'cc-by' => ['cc-by', true];
        yield 'cc-by versioned' => ['cc-by-4.0', true];
        yield 'cc-by-sa' => ['cc-by-sa', true];
        yield 'cc-by-sa versioned' => ['CC BY-SA 4.0', true];
        yield 'public domain' => ['public-domain', true];
        yield 'cc-by-nc rejected' => ['cc-by-nc', false];
        yield 'cc-by-nc-sa rejected' => ['cc-by-nc-sa-4.0', false];
        yield 'cc-by-nd rejected' => ['cc-by-nd', false];
        yield 'proprietary' => ['publisher-specific', false];
        yield 'null' => [null, false];
    }
}
