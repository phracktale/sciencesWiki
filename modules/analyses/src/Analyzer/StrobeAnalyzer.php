<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\RichFramework;
use Analyses\Framework\Strobe\StrobeFramework;

/**
 * Exécuteur STROBE RICHE : reporting des études observationnelles (reported / partially_reported /
 * not_reported), calibré item par item avec sortie structurée et ancrage ({@see AbstractRichAnalyzer}).
 */
final class StrobeAnalyzer extends AbstractRichAnalyzer
{
    private ?StrobeFramework $strobe = null;

    protected function framework(): RichFramework
    {
        return $this->strobe ??= new StrobeFramework();
    }
}
