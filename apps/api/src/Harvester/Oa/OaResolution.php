<?php

declare(strict_types=1);

namespace App\Harvester\Oa;

use App\Enum\OaStatus;

/**
 * Résultat d'une résolution d'accès ouvert pour un DOI (cf. Phase 1 §3.3).
 *
 * Fournit la meilleure version *légalement* accessible et sa licence.
 */
final class OaResolution
{
    public function __construct(
        public readonly bool $isOa,
        public readonly OaStatus $oaStatus,
        public readonly ?string $bestOaUrl = null,
        public readonly ?string $license = null,
        public readonly ?string $hostType = null,
        public readonly ?string $version = null,
    ) {
    }
}
