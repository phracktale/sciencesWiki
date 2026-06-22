<?php

declare(strict_types=1);

namespace App\Analysis\Controversy;

use App\Entity\Claim;

/**
 * Regroupement d'assertions partageant un même axe (exposure_norm, outcome_norm),
 * éventuellement fusionné par proximité d'embedding (cf. spec §6.1). Structure de
 * travail interne au {@see ControversyDetector}, sans persistance.
 */
final class ClaimCluster
{
    /** @var list<Claim> */
    public array $claims = [];

    public function __construct(
        public readonly string $exposureNorm,
        public readonly string $outcomeNorm,
    ) {
    }

    public function add(Claim $claim): void
    {
        $this->claims[] = $claim;
    }

    public function size(): int
    {
        return \count($this->claims);
    }
}
