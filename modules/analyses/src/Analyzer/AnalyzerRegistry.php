<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registre des analyseurs disponibles, indexés par référentiel exécuté.
 */
final class AnalyzerRegistry
{
    /** @var array<string, AnalyzerInterface> */
    private array $analyzers = [];

    /**
     * @param iterable<AnalyzerInterface> $analyzers
     */
    public function __construct(
        #[AutowireIterator('analyses.analyzer')]
        iterable $analyzers,
    ) {
        foreach ($analyzers as $analyzer) {
            $this->analyzers[$analyzer->frameworkId()] = $analyzer;
        }
    }

    public function get(string $frameworkId): ?AnalyzerInterface
    {
        return $this->analyzers[$frameworkId] ?? null;
    }
}
