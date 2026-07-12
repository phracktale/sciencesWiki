<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Rob2\Rob2Framework;
use Analyses\Sdk\LlmPort;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exécuteur RoB 2 : jugement par domaine sur l'échelle low / some_concerns / high.
 */
final class Rob2Analyzer extends AbstractCriteriaAnalyzer
{
    public function __construct(
        LlmPort $llm,
        private readonly Rob2Framework $rob2 = new Rob2Framework(),
        #[Autowire(env: 'ANALYS_MODEL')]
        string $model = 'glm-5.2:cloud',
    ) {
        parent::__construct($llm, $model);
    }

    public function frameworkId(): string
    {
        return 'rob2';
    }

    protected function criteria(): array
    {
        return $this->rob2->criteria();
    }

    protected function validAnswers(): array
    {
        return ['unclear', 'low', 'some_concerns', 'high'];
    }

    protected function unclearAnswer(): string
    {
        return 'unclear';
    }

    protected function promptIntro(): string
    {
        return "Tu es un méthodologiste. Évalue le RISQUE DE BIAIS d'un essai randomisé selon RoB 2, domaine par domaine. Pour chaque domaine : low (risque faible), some_concerns (préoccupations), ou high (risque élevé).";
    }
}
