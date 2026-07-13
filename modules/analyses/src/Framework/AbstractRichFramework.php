<?php

declare(strict_types=1);

namespace Analyses\Framework;

/**
 * Socle des référentiels calibrés « riches » : fabrique d'items avec valeurs par défaut et
 * implémentations communes (doctrine vide, pas d'applicabilité, seule la réponse incertaine
 * est non conclusive). Chaque référentiel n'a plus qu'à fournir sa présentation, son échelle
 * de réponse et ses items calibrés.
 */
abstract class AbstractRichFramework implements RichFramework
{
    public function doctrine(): string
    {
        return '';
    }

    public function applicabilityNote(): ?string
    {
        return null;
    }

    public function nonConclusiveAnswers(): array
    {
        return [$this->unclearAnswer()];
    }

    /**
     * Fabrique d'item calibré avec valeurs par défaut.
     *
     * @param array<string, string> $levels réponse → règle de décision
     *
     * @return array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}
     */
    protected static function item(
        string $id,
        string $section,
        string $question,
        string $help,
        string $expected,
        array $levels,
        string $where,
        bool $visual = false,
        bool $reverse = false,
        bool $na = false,
        string $special = '',
    ): array {
        return compact('id', 'section', 'question', 'help', 'expected', 'levels', 'where', 'visual', 'reverse', 'na', 'special');
    }
}
