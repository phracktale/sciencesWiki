<?php

declare(strict_types=1);

namespace App\Harvester\Pipeline;

/**
 * Portier de licence (cf. Phase 1 §5) : décide si le full-text d'une publication
 * peut être *stocké*, ou si l'on se limite aux métadonnées + lien.
 *
 * Politique par défaut (cf. spec §13, Q1) : on n'autorise le stockage que pour
 * des licences franchement libres — CC0 / domaine public / CC BY / CC BY-SA —
 * en excluant les variantes NC (non commercial) et ND (pas de dérivés). Tout le
 * reste demeure citable et vulgarisable, mais sans copie locale du full-text.
 */
final class LicenseGate
{
    public function mayStoreFullText(?string $license): bool
    {
        if (null === $license) {
            return false;
        }

        $normalized = strtolower(trim($license));
        $normalized = str_replace([' ', '_', '/'], '-', $normalized);

        if (\in_array($normalized, ['cc0', 'cc-0', 'public-domain', 'pd', 'pddl'], true)
            || str_starts_with($normalized, 'cc0-')
        ) {
            return true;
        }

        // Familles CC BY et CC BY-SA uniquement (on rejette NC et ND).
        if (str_starts_with($normalized, 'cc-by')) {
            if (str_contains($normalized, '-nc') || str_contains($normalized, '-nd')) {
                return false;
            }

            return true;
        }

        return false;
    }
}
