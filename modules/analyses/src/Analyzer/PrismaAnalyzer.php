<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Prisma\PrismaFramework;
use Analyses\Sdk\LlmPort;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exécuteur PRISMA (reporting des revues systématiques / méta-analyses).
 */
final class PrismaAnalyzer extends AbstractReportingAnalyzer
{
    public function __construct(
        LlmPort $llm,
        private readonly PrismaFramework $prisma = new PrismaFramework(),
        #[Autowire(env: 'ANALYS_MODEL')]
        string $model = 'glm-5.2:cloud',
    ) {
        parent::__construct($llm, $model);
    }

    public function frameworkId(): string
    {
        return 'prisma';
    }

    protected function criteria(): array
    {
        return $this->prisma->criteria();
    }

    protected function promptIntro(): string
    {
        return "Tu es un éditeur scientifique. Évalue la QUALITÉ DE REPORTING d'une revue systématique selon PRISMA. Pour chaque item : reported, partially_reported, not_reported, ou not_applicable.";
    }
}
