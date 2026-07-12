<?php

declare(strict_types=1);

namespace Analyses\Framework;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un référentiel d'analyse (grille critique, reporting, risque de biais…) déclaré
 * comme plugin (cf. docs/Modules/analyses/SPECS.md §7.4). Les implémentations sont
 * auto-taguées et collectées par {@see FrameworkRegistry}.
 */
#[AutoconfigureTag('analyses.framework')]
interface FrameworkInterface
{
    /** Identifiant stable du référentiel (ex. « axis »). */
    public function id(): string;

    /**
     * Métadonnées déclaratives : name, version, framework_type, supported_designs,
     * supported_domains, required_inputs, dimensions, incompatibilities.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array;
}
