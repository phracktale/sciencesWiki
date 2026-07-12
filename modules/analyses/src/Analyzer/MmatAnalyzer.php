<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Mmat\MmatFramework;
use Analyses\Sdk\LlmPort;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exécuteur MMAT : filtrage + critères méthodes mixtes, échelle yes / no / cant_tell.
 */
final class MmatAnalyzer extends AbstractCriteriaAnalyzer
{
    public function __construct(
        LlmPort $llm,
        private readonly MmatFramework $mmat = new MmatFramework(),
        #[Autowire(env: 'ANALYS_MODEL')]
        string $model = 'glm-5.2:cloud',
    ) {
        parent::__construct($llm, $model);
    }

    public function frameworkId(): string
    {
        return 'mmat';
    }

    protected function criteria(): array
    {
        return $this->mmat->criteria();
    }

    protected function validAnswers(): array
    {
        return ['cant_tell', 'yes', 'no'];
    }

    protected function unclearAnswer(): string
    {
        return 'cant_tell';
    }

    protected function promptIntro(): string
    {
        return "Tu es un méthodologiste. Évalue une étude à MÉTHODES MIXTES selon MMAT. Pour chaque critère : yes, no, ou cant_tell (impossible à déterminer d'après le texte).";
    }
}
