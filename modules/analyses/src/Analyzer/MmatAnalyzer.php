<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Mmat\MmatFramework;
use Analyses\Framework\RichFramework;

/**
 * Exécuteur MMAT RICHE : filtrage + catégorie méthodes mixtes (yes / no / cant_tell),
 * calibré item par item, sortie structurée et ancrage littéral ({@see AbstractRichAnalyzer}).
 */
final class MmatAnalyzer extends AbstractRichAnalyzer
{
    private ?MmatFramework $mmat = null;

    protected function framework(): RichFramework
    {
        return $this->mmat ??= new MmatFramework();
    }
}
