<?php

declare(strict_types=1);

namespace App\Harvester\Support;

/**
 * Normalisation des DOI, clé de dédoublonnage des publications (cf. Phase 1 §6.2).
 */
final class Doi
{
    /**
     * Normalise un DOI : retire le préfixe URL ou « doi: », met en minuscule et
     * supprime les espaces. Renvoie null si la chaîne n'est pas un DOI plausible.
     */
    public static function normalize(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        $doi = trim($raw);
        if ('' === $doi) {
            return null;
        }

        // Retire les variantes de préfixe : https://doi.org/, http://dx.doi.org/, doi:
        $doi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi) ?? $doi;
        $doi = preg_replace('#^doi:#i', '', $doi) ?? $doi;
        $doi = strtolower(trim($doi));

        // Un DOI commence toujours par « 10. » suivi d'un préfixe d'enregistrement.
        if (!str_starts_with($doi, '10.') || !str_contains($doi, '/')) {
            return null;
        }

        return $doi;
    }
}
