<?php

declare(strict_types=1);

namespace Analyses\Framework;

/**
 * Référentiel CALIBRÉ « riche » — même niveau d'exigence qu'AXIS pour TOUS les référentiels
 * (RoB 2, AMSTAR 2, MMAT, STROBE, CONSORT, PRISMA). Fournit, pour chaque item : la question,
 * l'aide officielle, l'attendu, la grille de décision par niveau et où chercher. Le
 * {@see RichPromptBuilder} en dérive un prompt système structuré, et {@see \Analyses\Analyzer\AbstractRichAnalyzer}
 * produit la sortie riche ancrée (verdict/expected/evidence_found/analysis/limitations/evidence[]).
 *
 * Un item est un tableau :
 *   id       : identifiant du critère (préfixé par le référentiel, ex. « rob2.d1 »)
 *   section  : dimension / regroupement affiché
 *   question : la question évaluée
 *   help     : aide officielle courte (une ligne)
 *   expected : ce que l'article DOIT fournir pour la meilleure réponse
 *   levels   : map réponse → règle de décision (ex. « low » => « … », « high » => « … »)
 *   where    : où chercher dans l'article
 *   visual   : requires_visual_check par défaut (dépend probablement d'un tableau/figure)
 *   reverse  : un « oui » est DÉFAVORABLE (item inversé)
 *   na       : la réponse « na » est autorisée pour cet item
 *   special  : règle spéciale éventuelle (chaîne vide sinon)
 *
 * @phpstan-type RichItem array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}
 */
interface RichFramework
{
    public function id(): string;

    /** Présentation de l'outil et de son périmètre (1er paragraphe du prompt système). */
    public function toolIntro(): string;

    /** Doctrine de décision propre au référentiel (sévérité, cas particuliers). Peut être ''. */
    public function doctrine(): string;

    /**
     * Échelle de réponse : valeur → signification, de la PLUS favorable à la MOINS favorable.
     *
     * @return array<string, string>
     */
    public function answerScale(): array;

    /** Réponse « incertaine » (repli non conclusif). */
    public function unclearAnswer(): string;

    /**
     * Réponses NON conclusives (n'exigent pas d'ancrage). Contient au moins la réponse
     * incertaine ; « na » y est ajouté automatiquement.
     *
     * @return list<string>
     */
    public function nonConclusiveAnswers(): array;

    /**
     * Étape 0 d'applicabilité (contrôle du design) : consigne, ou null si toujours applicable.
     */
    public function applicabilityNote(): ?string;

    /**
     * @return list<array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}>
     */
    public function richItems(): array;
}
