<?php

declare(strict_types=1);

namespace App\Tests\Harvester\Connector\OpenAlex;

use App\Harvester\Connector\OpenAlex\AbstractReconstructor;
use PHPUnit\Framework\TestCase;

final class AbstractReconstructorTest extends TestCase
{
    public function testReconstructOrdersWordsByPosition(): void
    {
        $inverted = [
            'Le' => [0],
            'chat' => [1],
            'noir' => [2, 4],
            'dort' => [3],
        ];

        self::assertSame('Le chat noir dort noir', AbstractReconstructor::reconstruct($inverted));
    }

    public function testReconstructReturnsNullForEmpty(): void
    {
        self::assertNull(AbstractReconstructor::reconstruct(null));
        self::assertNull(AbstractReconstructor::reconstruct([]));
    }
}
