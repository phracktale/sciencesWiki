<?php

declare(strict_types=1);

namespace App\Harvester\Oa\Unpaywall;

use App\Enum\OaStatus;
use App\Harvester\Oa\OaResolution;

/**
 * Convertit une réponse Unpaywall (/v2/{doi}) en {@see OaResolution}.
 *
 * Classe pure (sans I/O) afin d'être testable avec une fixture JSON.
 */
final class UnpaywallMapper
{
    public const SOURCE_CODE = 'unpaywall';

    /**
     * @param array<string,mixed> $response
     */
    public function map(array $response): OaResolution
    {
        $isOa = (bool) ($response['is_oa'] ?? false);
        $best = \is_array($response['best_oa_location'] ?? null) ? $response['best_oa_location'] : [];

        $url = $best['url_for_pdf'] ?? ($best['url'] ?? null);

        return new OaResolution(
            isOa: $isOa,
            oaStatus: OaStatus::fromApi($response['oa_status'] ?? null),
            bestOaUrl: null !== $url ? (string) $url : null,
            license: isset($best['license']) ? (string) $best['license'] : null,
            hostType: isset($best['host_type']) ? (string) $best['host_type'] : null,
            version: isset($best['version']) ? (string) $best['version'] : null,
        );
    }
}
