<?php

declare(strict_types=1);

namespace Analyses\Framework\Strobe;

use Analyses\Framework\AbstractReportingRichFramework;

/**
 * STROBE (STrengthening the Reporting of OBservational studies in Epidemiology, 2007) —
 * grille de REPORTING des études observationnelles (transversales, cohortes, cas-témoins),
 * CALIBRÉE « riche » au niveau AXIS. 22 items ; évalue la qualité de DESCRIPTION, pas la validité.
 */
final class StrobeFramework extends AbstractReportingRichFramework
{
    public function id(): string
    {
        return 'strobe';
    }

    public function metadata(): array
    {
        return [
            'name' => 'STROBE',
            'version' => '2007',
            'framework_type' => 'reporting_guideline',
            'supported_designs' => ['cross_sectional', 'cohort_prospective', 'cohort_retrospective', 'case_control', 'ecological'],
            'supported_domains' => ['*'],
            'required_inputs' => ['full_text'],
            'dimensions' => ['reporting_quality'],
            'incompatibilities' => ['randomized_controlled_trial', 'systematic_review'],
            'criteria_count' => \count($this->richItems()),
        ];
    }

    public function toolIntro(): string
    {
        return <<<TXT
            Tu es un éditeur scientifique appliquant la grille de reporting STROBE (2007) à une ÉTUDE
            OBSERVATIONNELLE (transversale, cohorte ou cas-témoins). Pour chaque item, juge si
            l'élément attendu est RAPPORTÉ : « reported », « partially_reported », « not_reported »
            ou « unclear ». Tu évalues la qualité de DESCRIPTION, jamais la validité des résultats.
            TXT;
    }

    /** @return list<array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}> */
    public function richItems(): array
    {
        return [
            self::ritem('strobe.01', 'Titre & résumé', "Le titre/résumé indiquent-ils le plan d'étude et résument-ils ce qui a été fait/trouvé ?",
                "Le design (ex. « étude transversale », « cohorte ») doit apparaître dans le titre ou le résumé, ET le résumé doit donner un compte rendu informatif et équilibré (objectif, méthode, résultats).",
                "Title, Abstract."),
            self::ritem('strobe.02', 'Contexte', "Le contexte scientifique et la justification de l'étude sont-ils expliqués ?",
                "L'introduction doit exposer l'état des connaissances et la justification de l'étude.",
                "Introduction (background, rationale)."),
            self::ritem('strobe.03', 'Objectifs', "Les objectifs spécifiques, y compris les hypothèses, sont-ils énoncés ?",
                "Objectifs et, le cas échéant, hypothèses pré-spécifiées explicitement formulés.",
                "Introduction (objectives), Abstract."),
            self::ritem('strobe.04', 'Plan d\'étude', "Les éléments clés du plan d'étude sont-ils présentés tôt dans l'article ?",
                "Le type d'étude doit être nommé et situé tôt (résumé/début des méthodes).",
                "Methods (study design), Abstract."),
            self::ritem('strobe.05', 'Cadre', "Le cadre, les lieux et les dates pertinentes (recrutement, exposition, suivi, collecte) sont-ils décrits ?",
                "Lieu(x), période de recrutement, dates d'exposition/suivi et de collecte des données.",
                "Methods (setting, study period, recruitment dates)."),
            self::ritem('strobe.06', 'Participants', "Les critères d'éligibilité, sources et méthodes de sélection des participants sont-ils décrits ?",
                "Critères d'inclusion/exclusion, sources de recrutement et méthode de sélection (et pour cas-témoins/cohorte : appariement, choix des cas/témoins ou exposés/non exposés).",
                "Methods (participants, eligibility, sampling)."),
            self::ritem('strobe.07', 'Variables', "Les résultats, expositions, prédicteurs, facteurs de confusion et modificateurs d'effet sont-ils clairement définis ?",
                "Définition explicite de chaque variable : outcome(s), exposition(s), confondants potentiels, modificateurs d'effet.",
                "Methods (variables, definitions).", visual: true),
            self::ritem('strobe.08', 'Mesure', "Pour chaque variable, les sources de données et méthodes de mesure/évaluation sont-elles décrites ?",
                "Source des données et méthode de mesure pour chaque variable (comparabilité des méthodes entre groupes si plusieurs groupes).",
                "Methods (data sources, measurement, instruments)."),
            self::ritem('strobe.09', 'Biais', "Les efforts pour traiter les sources potentielles de biais sont-ils décrits ?",
                "Description des mesures prises contre les biais (sélection, information, confusion).",
                "Methods (bias)."),
            self::ritem('strobe.10', 'Taille d\'étude', "La façon dont la taille d'étude a été déterminée est-elle expliquée ?",
                "Justification de la taille d'échantillon (calcul de puissance ou raison pragmatique explicite).",
                "Methods (study size, sample size)."),
            self::ritem('strobe.11', 'Variables quantitatives', "Le traitement des variables quantitatives (regroupements, catégorisations) est-il expliqué ?",
                "Comment les variables quantitatives ont été analysées et, le cas échéant, quels regroupements et pourquoi.",
                "Methods (statistical analysis, variable handling)."),
            self::ritem('strobe.12', 'Méthodes statistiques', "Les méthodes statistiques (confusion, sous-groupes/interactions, données manquantes, sensibilité) sont-elles décrites ?",
                "Toutes les méthodes statistiques, y compris contrôle de la confusion, analyses de sous-groupes/interactions, traitement des données manquantes, analyses de sensibilité, et gestion spécifique au design (perdus de vue, appariement…).",
                "Methods (statistical methods)."),
            self::ritem('strobe.13', 'Participants (flux)', "Le nombre de participants à chaque étape (éligibles, examinés, inclus, analysés) est-il rapporté ?",
                "Effectifs à chaque étape et raisons des non-inclusions ; idéalement un diagramme de flux.",
                "Results (participants), flow diagram.", visual: true),
            self::ritem('strobe.14', 'Données descriptives', "Les caractéristiques des participants et le nombre de données manquantes par variable sont-ils décrits ?",
                "Caractéristiques des participants (démographiques, cliniques, sociales) ET nombre de valeurs manquantes par variable d'intérêt.",
                "Results, Table 1 (baseline characteristics).", visual: true),
            self::ritem('strobe.15', 'Données de résultat', "Les données de résultat (nombres d'événements ou mesures synthétiques) sont-elles rapportées ?",
                "Nombre d'événements/de cas ou mesures résumées de l'outcome, par groupe le cas échéant, selon le design.",
                "Results, tables.", visual: true),
            self::ritem('strobe.16', 'Résultats principaux', "Les résultats principaux (estimations, intervalles de confiance, ajustement des confondants) sont-ils rapportés ?",
                "Estimations non ajustées ET ajustées avec intervalles de confiance, et précision des confondants ajustés (idéalement risques absolus sur une période pertinente).",
                "Results (main results), tables.", visual: true),
            self::ritem('strobe.17', 'Autres analyses', "Les autres analyses réalisées (sous-groupes, interactions, sensibilité) sont-elles rapportées ?",
                "Rapport des analyses secondaires prévues (sous-groupes, interactions, sensibilité).",
                "Results (other analyses).", na: true),
            self::ritem('strobe.18', 'Résultats clés', "Les résultats clés sont-ils résumés au regard des objectifs de l'étude ?",
                "Synthèse des principaux résultats en lien avec les objectifs, en ouverture de la discussion.",
                "Discussion (key results)."),
            self::ritem('strobe.19', 'Limites', "Les limites de l'étude (sources de biais ou d'imprécision, direction du biais) sont-elles discutées ?",
                "Discussion des limites : sources de biais/imprécision et, si possible, ampleur et direction du biais potentiel.",
                "Discussion (limitations)."),
            self::ritem('strobe.20', 'Interprétation', "L'interprétation est-elle prudente (prise en compte des objectifs, limites, multiplicité, causalité) ?",
                "Interprétation globale prudente tenant compte des objectifs, limites, analyses multiples et preuves comparables ; pas de surinterprétation causale.",
                "Discussion (interpretation)."),
            self::ritem('strobe.21', 'Généralisabilité', "La généralisabilité (validité externe) des résultats est-elle discutée ?",
                "Discussion de la validité externe / transposabilité des résultats.",
                "Discussion (generalisability)."),
            self::ritem('strobe.22', 'Financement', "Le financement et le rôle des financeurs (pour l'étude et, le cas échéant, l'étude d'origine des données) sont-ils indiqués ?",
                "Source de financement ET rôle du financeur dans l'étude.",
                "Funding, Declarations, Acknowledgements."),
        ];
    }
}
