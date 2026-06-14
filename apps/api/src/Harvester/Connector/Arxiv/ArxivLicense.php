<?php

declare(strict_types=1);

namespace App\Harvester\Connector\Arxiv;

/**
 * Normalise une licence arXiv (fournie sous forme d'URL) en jeton canonique
 * compris par le {@see \App\Harvester\Pipeline\LicenseGate}.
 */
final class ArxivLicense
{
    public static function normalize(?string $url): ?string
    {
        if (null === $url) {
            return null;
        }

        $u = strtolower(trim($url));
        if ('' === $u) {
            return null;
        }

        if (str_contains($u, 'publicdomain/zero')) {
            return 'cc0';
        }
        if (str_contains($u, 'creativecommons.org')) {
            // ex. .../licenses/by-nc-sa/4.0/ → cc-by-nc-sa
            if (preg_match('#/licenses/([a-z-]+)/#', $u, $m)) {
                return 'cc-'.$m[1];
            }
        }
        if (str_contains($u, 'arxiv.org/licenses/nonexclusive-distrib')) {
            // Licence de distribution non exclusive d'arXiv : pas de libre
            // redistribution → non stockable.
            return 'arxiv-nonexclusive';
        }

        return null;
    }
}
