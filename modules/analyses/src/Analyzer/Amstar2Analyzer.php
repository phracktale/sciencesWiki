<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Amstar2\Amstar2Framework;
use Analyses\Framework\RichFramework;

/**
 * Exécuteur AMSTAR 2 RICHE : 16 items (dont 7 critiques), échelle yes / partial_yes / no,
 * calibré item par item avec sortie structurée et ancrage littéral ({@see AbstractRichAnalyzer}).
 */
final class Amstar2Analyzer extends AbstractRichAnalyzer
{
    private ?Amstar2Framework $amstar2 = null;

    protected function framework(): RichFramework
    {
        return $this->amstar2 ??= new Amstar2Framework();
    }
}
