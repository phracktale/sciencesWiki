<?php

declare(strict_types=1);

namespace App\Tests\Harvester\Support;

use App\Harvester\Support\Doi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DoiTest extends TestCase
{
    #[DataProvider('provideDois')]
    public function testNormalize(?string $input, ?string $expected): void
    {
        self::assertSame($expected, Doi::normalize($input));
    }

    /**
     * @return iterable<string,array{?string,?string}>
     */
    public static function provideDois(): iterable
    {
        yield 'url https' => ['https://doi.org/10.1234/ABC.def', '10.1234/abc.def'];
        yield 'url dx' => ['http://dx.doi.org/10.1/X', '10.1/x'];
        yield 'prefix doi:' => ['doi:10.1000/182', '10.1000/182'];
        yield 'already bare' => ['10.5555/12345678', '10.5555/12345678'];
        yield 'whitespace' => ['  10.1/abc  ', '10.1/abc'];
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'not a doi' => ['hello world', null];
        yield 'missing slash' => ['10.1234', null];
        yield 'missing prefix' => ['11.1234/x', null];
    }
}
