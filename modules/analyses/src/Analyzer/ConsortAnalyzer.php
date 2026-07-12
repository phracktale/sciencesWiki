<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Consort\ConsortFramework;
use Analyses\Sdk\LlmPort;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exécuteur CONSORT (reporting des essais randomisés).
 */
final class ConsortAnalyzer extends AbstractReportingAnalyzer
{
    public function __construct(
        LlmPort $llm,
        private readonly ConsortFramework $consort = new ConsortFramework(),
        #[Autowire(env: 'ANALYS_MODEL')]
        string $model = 'glm-5.2:cloud',
    ) {
        parent::__construct($llm, $model);
    }

    public function frameworkId(): string
    {
        return 'consort';
    }

    protected function criteria(): array
    {
        return $this->consort->criteria();
    }

    protected function promptIntro(): string
    {
        return "Tu es un éditeur scientifique. Évalue la QUALITÉ DE REPORTING d'un essai randomisé selon CONSORT. Pour chaque item : reported, partially_reported, not_reported, ou not_applicable.";
    }
}
