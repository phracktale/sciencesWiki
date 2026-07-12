<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Exécuteur d'un référentiel : interroge les sources et produit des réponses ancrées
 * (SPECS §16). Auto-tagué et sélectionné par l'orchestrateur selon le plan de routage.
 */
#[AutoconfigureTag('analyses.analyzer')]
interface AnalyzerInterface
{
    /** Référentiel exécuté (doit correspondre à un id de {@see \Analyses\Framework\FrameworkInterface}). */
    public function frameworkId(): string;

    /**
     * @param array<string, mixed> $meta métadonnées de publication
     *
     * @return array{criteria: list<array<string, mixed>>, overall: array<string, mixed>}
     */
    public function analyze(string $fulltext, array $meta): array;
}
