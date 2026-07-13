<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Prisma\PrismaFramework;
use Analyses\Framework\RichFramework;

/**
 * Exécuteur PRISMA RICHE : reporting des revues systématiques / méta-analyses (reported /
 * partially_reported / not_reported), calibré item par item ({@see AbstractRichAnalyzer}).
 */
final class PrismaAnalyzer extends AbstractRichAnalyzer
{
    private ?PrismaFramework $prisma = null;

    protected function framework(): RichFramework
    {
        return $this->prisma ??= new PrismaFramework();
    }
}
