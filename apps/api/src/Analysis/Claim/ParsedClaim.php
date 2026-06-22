<?php

declare(strict_types=1);

namespace App\Analysis\Claim;

use App\Enum\ClaimConfidence;
use App\Enum\ClaimDirection;
use App\Enum\ClaimMethod;

/**
 * Entrée d'extraction validée (issue du JSON LLM), avant persistance en Claim
 * (cf. docs/spec-controverses-lacunes.md §5). Immuable.
 */
final class ParsedClaim
{
    /** @param list<string> $futureWork */
    public function __construct(
        public readonly string $exposure,
        public readonly string $outcome,
        public readonly ClaimDirection $direction,
        public readonly ClaimMethod $method,
        public readonly ClaimConfidence $confidence,
        public readonly ?string $population,
        public readonly ?int $sampleSize,
        public readonly ?string $effectSize,
        public readonly ?string $statedLimitations,
        public readonly array $futureWork,
        public readonly string $quote,
    ) {
    }
}
