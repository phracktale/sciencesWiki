<?php

declare(strict_types=1);

namespace App\Harvester\Pipeline;

use App\Entity\Publication;

final class ImportResult
{
    public function __construct(
        public readonly Publication $publication,
        public readonly bool $created,
    ) {
    }
}
