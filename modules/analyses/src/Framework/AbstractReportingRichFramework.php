<?php

declare(strict_types=1);

namespace Analyses\Framework;

/**
 * Socle des référentiels de REPORTING calibrés « riches » (STROBE, CONSORT, PRISMA). L'échelle
 * est uniforme (reported / partially_reported / not_reported / unclear) : chaque item ne fournit
 * donc que CE QUI doit être rapporté (« expected ») et où le chercher, la sémantique des niveaux
 * étant portée une seule fois par l'échelle et la doctrine. Le garde-fou d'ancrage s'applique :
 * « reported »/« partially_reported » exigent une citation vérifiée, « not_reported » une absence
 * vérifiée sur tout le texte fourni.
 */
abstract class AbstractReportingRichFramework extends AbstractRichFramework implements FrameworkInterface
{
    public function answerScale(): array
    {
        return [
            'reported' => "l'élément attendu est explicitement et complètement rapporté dans le texte.",
            'partially_reported' => "l'élément est partiellement rapporté (certains sous-éléments présents, d'autres manquants ou vagues).",
            'not_reported' => "l'élément attendu est absent du texte fourni (absence vérifiée sur toutes les sections).",
            'unclear' => "impossible de trancher : l'information dépend probablement d'un tableau/figure non transcrit, ou le texte est trop fragmentaire.",
        ];
    }

    public function unclearAnswer(): string
    {
        return 'unclear';
    }

    public function doctrine(): string
    {
        return "Ces grilles évaluent la QUALITÉ DE REPORTING (l'élément est-il décrit ?), PAS la validité scientifique. « reported » suppose une description effective adossée à une citation ; une simple allusion relève de « partially_reported ». Réponds « not_reported » seulement si l'élément est vérifié ABSENT de tout le texte fourni ; si l'élément est probablement dans un tableau/figure non transcrit, réponds « unclear » (et requires_visual_check=true).";
    }

    /**
     * Fabrique d'item de reporting : pas de grille par niveau (l'échelle est uniforme), juste
     * l'attendu et où chercher.
     *
     * @return array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}
     */
    protected static function ritem(
        string $id,
        string $section,
        string $question,
        string $expected,
        string $where,
        bool $visual = false,
        bool $na = false,
        string $special = '',
    ): array {
        return self::item($id, $section, $question, '', $expected, [], $where, $visual, false, $na, $special);
    }

    /** @return list<array{id: string, dimension: string, question: string}> */
    public function criteria(): array
    {
        return array_map(
            static fn (array $it): array => ['id' => $it['id'], 'dimension' => $it['section'], 'question' => $it['question']],
            $this->richItems(),
        );
    }
}
