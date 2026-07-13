<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Consort\ConsortFramework;
use Analyses\Framework\RichFramework;

/**
 * Exécuteur CONSORT RICHE : reporting des essais randomisés (reported / partially_reported /
 * not_reported), calibré item par item avec sortie structurée et ancrage ({@see AbstractRichAnalyzer}).
 */
final class ConsortAnalyzer extends AbstractRichAnalyzer
{
    private ?ConsortFramework $consort = null;

    protected function framework(): RichFramework
    {
        return $this->consort ??= new ConsortFramework();
    }
}
