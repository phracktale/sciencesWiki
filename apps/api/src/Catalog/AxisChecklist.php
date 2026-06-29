<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Les 20 items de l'outil AXIS — *Appraisal tool for Cross-Sectional Studies*
 * (Downes MJ, Brennan ML, Williams HC, Dean RS. BMJ Open 2016;6:e011458.
 * doi:10.1136/bmjopen-2016-011458), regroupés par section, traduits en français.
 *
 * Note méthodologique (cf. docs/spec-axis-articles.md §2) : les auteurs d'AXIS
 * DÉCONSEILLENT un score numérique global. La grille reste une *checklist* ;
 * {@see self::REVERSE} marque les deux items pour lesquels un « Oui » est
 * DÉFAVORABLE (q13 biais de non-réponse, q19 conflits d'intérêts), afin de
 * calculer une bande indicative de fiabilité — jamais une note ferme.
 */
final class AxisChecklist
{
    /**
     * Question (clé `q1`…`q20`) → { section, text }. Ordre = ordre officiel AXIS.
     *
     * @var array<string,array{section:string,text:string}>
     */
    public const ITEMS = [
        'q1' => ['section' => 'Introduction', 'text' => 'Les objectifs de l’étude étaient-ils clairs ?'],
        'q2' => ['section' => 'Méthodes', 'text' => 'Le plan d’étude était-il adapté aux objectifs énoncés ?'],
        'q3' => ['section' => 'Méthodes', 'text' => 'La taille de l’échantillon était-elle justifiée ?'],
        'q4' => ['section' => 'Méthodes', 'text' => 'La population cible / de référence était-elle clairement définie ?'],
        'q5' => ['section' => 'Méthodes', 'text' => 'La base de sondage était-elle issue d’une population représentant fidèlement la population cible ?'],
        'q6' => ['section' => 'Méthodes', 'text' => 'Le processus de sélection avait-il toutes les chances de produire des participants représentatifs de la population cible ?'],
        'q7' => ['section' => 'Méthodes', 'text' => 'Des mesures ont-elles été prises pour traiter et catégoriser les non-répondants ?'],
        'q8' => ['section' => 'Méthodes', 'text' => 'Les variables d’exposition et de résultat mesurées étaient-elles adaptées aux objectifs ?'],
        'q9' => ['section' => 'Méthodes', 'text' => 'Les variables ont-elles été mesurées avec des instruments éprouvés, pilotés ou déjà publiés ?'],
        'q10' => ['section' => 'Méthodes', 'text' => 'Est-il clair ce qui a servi à déterminer la significativité statistique et/ou les estimations de précision (p, IC) ?'],
        'q11' => ['section' => 'Méthodes', 'text' => 'Les méthodes (y compris statistiques) étaient-elles suffisamment décrites pour être reproduites ?'],
        'q12' => ['section' => 'Résultats', 'text' => 'Les données de base étaient-elles décrites de façon adéquate ?'],
        'q13' => ['section' => 'Résultats', 'text' => 'Le taux de réponse soulève-t-il des inquiétudes quant à un biais de non-réponse ?'],
        'q14' => ['section' => 'Résultats', 'text' => 'Le cas échéant, l’information sur les non-répondants était-elle décrite ?'],
        'q15' => ['section' => 'Résultats', 'text' => 'Les résultats étaient-ils cohérents en interne ?'],
        'q16' => ['section' => 'Résultats', 'text' => 'Les résultats étaient-ils présentés pour toutes les analyses décrites dans les méthodes ?'],
        'q17' => ['section' => 'Discussion', 'text' => 'Les discussions et conclusions des auteurs étaient-elles justifiées par les résultats ?'],
        'q18' => ['section' => 'Discussion', 'text' => 'Les limites de l’étude ont-elles été discutées ?'],
        'q19' => ['section' => 'Autre', 'text' => 'Existait-il des sources de financement ou des conflits d’intérêts susceptibles d’affecter l’interprétation des auteurs ?'],
        'q20' => ['section' => 'Autre', 'text' => 'Un avis éthique ou le consentement des participants a-t-il été obtenu ?'],
    ];

    /**
     * Items à polarité INVERSÉE : un « Oui » est défavorable à la qualité.
     * q13 = inquiétude de biais de non-réponse ; q19 = conflit d’intérêts.
     *
     * @var list<string>
     */
    public const REVERSE = ['q13', 'q19'];

    /** Clés ordonnées q1…q20. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ITEMS);
    }

    /** Une réponse compte-t-elle FAVORABLEMENT pour la bande indicative ? */
    public static function isFavorable(string $key, \App\Enum\AxisAnswer $answer): bool
    {
        $favorable = \in_array($key, self::REVERSE, true)
            ? \App\Enum\AxisAnswer::No
            : \App\Enum\AxisAnswer::Yes;

        return $answer === $favorable;
    }
}
