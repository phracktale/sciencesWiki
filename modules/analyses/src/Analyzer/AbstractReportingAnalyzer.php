<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

/**
 * Socle des analyseurs de REPORTING (STROBE, CONSORT, PRISMA…) : échelle « l'item est-il
 * rapporté ? » plutôt que oui/non de qualité. Le garde-fou d'ancrage s'applique de la même
 * façon (une mention « reported » doit être adossée à une citation vérifiée).
 */
abstract class AbstractReportingAnalyzer extends AbstractCriteriaAnalyzer
{
    protected function validAnswers(): array
    {
        return ['unclear', 'reported', 'partially_reported', 'not_reported', 'not_applicable'];
    }

    protected function unclearAnswer(): string
    {
        return 'unclear';
    }
}
