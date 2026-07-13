<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\RichFramework;
use Analyses\Framework\Rob2\Rob2Framework;

/**
 * Exécuteur RoB 2 RICHE : jugement de risque de biais par domaine (low / some_concerns / high),
 * calibré item par item, avec sortie structurée et ancrage littéral (via {@see AbstractRichAnalyzer}).
 */
final class Rob2Analyzer extends AbstractRichAnalyzer
{
    private ?Rob2Framework $rob2 = null;

    protected function framework(): RichFramework
    {
        return $this->rob2 ??= new Rob2Framework();
    }
}
