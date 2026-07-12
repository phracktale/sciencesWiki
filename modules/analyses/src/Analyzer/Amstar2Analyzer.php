<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Amstar2\Amstar2Framework;
use Analyses\Sdk\LlmPort;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exécuteur AMSTAR 2 : 16 items, échelle yes / partial_yes / no.
 */
final class Amstar2Analyzer extends AbstractCriteriaAnalyzer
{
    public function __construct(
        LlmPort $llm,
        private readonly Amstar2Framework $amstar2 = new Amstar2Framework(),
        #[Autowire(env: 'ANALYS_MODEL')]
        string $model = 'glm-5.2:cloud',
    ) {
        parent::__construct($llm, $model);
    }

    public function frameworkId(): string
    {
        return 'amstar2';
    }

    protected function criteria(): array
    {
        return $this->amstar2->criteria();
    }

    protected function validAnswers(): array
    {
        return ['unclear', 'yes', 'partial_yes', 'no'];
    }

    protected function unclearAnswer(): string
    {
        return 'unclear';
    }

    protected function promptIntro(): string
    {
        return "Tu es un méthodologiste. Évalue la QUALITÉ MÉTHODOLOGIQUE d'une revue systématique selon AMSTAR 2. Pour chaque item : yes, partial_yes (partiellement satisfait), ou no.";
    }
}
