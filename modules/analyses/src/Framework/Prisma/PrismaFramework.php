<?php

declare(strict_types=1);

namespace Analyses\Framework\Prisma;

use Analyses\Framework\AbstractReportingRichFramework;

/**
 * PRISMA 2020 (Preferred Reporting Items for Systematic reviews and Meta-Analyses) — grille de
 * REPORTING des revues systématiques et méta-analyses, CALIBRÉE « riche » au niveau AXIS. Les 27
 * items PRISMA sont regroupés en 18 rubriques ; on évalue la qualité de DESCRIPTION, pas la validité.
 */
final class PrismaFramework extends AbstractReportingRichFramework
{
    public function id(): string
    {
        return 'prisma';
    }

    public function metadata(): array
    {
        return [
            'name' => 'PRISMA',
            'version' => '2020',
            'framework_type' => 'reporting_guideline',
            'supported_designs' => ['systematic_review', 'meta_analysis'],
            'supported_domains' => ['*'],
            'required_inputs' => ['full_text'],
            'dimensions' => ['reporting_quality'],
            'incompatibilities' => ['randomized_controlled_trial', 'cross_sectional'],
            'criteria_count' => \count($this->richItems()),
        ];
    }

    public function toolIntro(): string
    {
        return <<<TXT
            Tu es un éditeur scientifique appliquant la grille de reporting PRISMA 2020 à une REVUE
            SYSTÉMATIQUE ou MÉTA-ANALYSE. Pour chaque item, juge si l'élément attendu est RAPPORTÉ :
            « reported », « partially_reported », « not_reported » ou « unclear ». Tu évalues la
            qualité de DESCRIPTION du compte rendu, pas la validité des conclusions de la revue.
            TXT;
    }

    /** @return list<array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}> */
    public function richItems(): array
    {
        return [
            self::ritem('prisma.01', 'Titre', "Le titre identifie-t-il l'article comme une revue systématique ?",
                "La mention « revue systématique » (et « méta-analyse » le cas échéant) dans le titre.",
                "Title."),
            self::ritem('prisma.02', 'Résumé', "Un résumé structuré est-il fourni (objectifs, méthodes, résultats, conclusions) ?",
                "Résumé structuré conforme à PRISMA-Abstract (contexte, objectifs, sources, critères, résultats de synthèse, conclusions).",
                "Abstract."),
            self::ritem('prisma.03', 'Justification', "La justification de la revue est-elle exposée au regard des connaissances existantes ?",
                "Rationnel de la revue dans le contexte de ce qui est déjà connu.",
                "Introduction (rationale)."),
            self::ritem('prisma.04', 'Objectifs', "Les objectifs ou questions traités sont-ils énoncés explicitement (PICO) ?",
                "Énoncé explicite des objectifs/questions avec les composantes PICO.",
                "Introduction (objectives)."),
            self::ritem('prisma.05', "Critères d'éligibilité", "Les critères d'inclusion et d'exclusion sont-ils spécifiés, avec les regroupements pour la synthèse ?",
                "Critères d'inclusion/exclusion et modalités de regroupement des études pour les synthèses.",
                "Methods (eligibility criteria)."),
            self::ritem('prisma.06', "Sources d'information", "Les sources d'information (bases de données, registres, dates de dernière recherche) sont-elles décrites ?",
                "Toutes les sources consultées (bases, registres, listes de références, experts) ET la date de dernière recherche de chacune.",
                "Methods (information sources)."),
            self::ritem('prisma.07', 'Stratégie de recherche', "La stratégie de recherche complète est-elle fournie de façon reproductible (au moins une base) ?",
                "Équation de recherche complète pour au moins une base, avec filtres/limites utilisés.",
                "Methods (search strategy), Supplementary (full search string).", visual: true),
            self::ritem('prisma.08', 'Processus de sélection', "Le processus de sélection (nombre d'évaluateurs, indépendance, outils) est-il décrit ?",
                "Nombre d'évaluateurs pour le tri, travail indépendant ou non, procédure de résolution des désaccords, outils d'automatisation éventuels.",
                "Methods (selection process)."),
            self::ritem('prisma.09', 'Extraction des données', "Le processus d'extraction des données (évaluateurs, indépendance, variables) est-il décrit ?",
                "Méthode d'extraction : nombre d'extracteurs, indépendance, données recherchées et hypothèses/simplifications faites.",
                "Methods (data collection process, data items)."),
            self::ritem('prisma.10', 'Évaluation du risque de biais', "La méthode d'évaluation du risque de biais des études incluses est-elle décrite ?",
                "Outil de RoB utilisé, nombre d'évaluateurs et leur indépendance.",
                "Methods (risk of bias assessment)."),
            self::ritem('prisma.11', 'Méthodes de synthèse', "Les méthodes de synthèse (mesure d'effet, modèle de méta-analyse, hétérogénéité, sous-groupes) sont-elles décrites ?",
                "Mesures d'effet, méthodes de synthèse/modèle, gestion de l'hétérogénéité, analyses de sous-groupes/sensibilité, méthode de préparation des données.",
                "Methods (synthesis methods, effect measures)."),
            self::ritem('prisma.12', 'Sélection des études (résultats)', "Le flux de sélection des études (nombre identifié, retenu, exclu avec raisons) est-il rapporté, idéalement par un diagramme ?",
                "Nombres à chaque étape (identifiés, dédoublonnés, examinés, inclus/exclus avec raisons) — idéalement un diagramme de flux PRISMA.",
                "Results, PRISMA flow diagram.", visual: true),
            self::ritem('prisma.13', 'Caractéristiques des études', "Les caractéristiques des études incluses sont-elles présentées et citées ?",
                "Tableau des caractéristiques de chaque étude incluse (PICO, design, cadre), avec citation.",
                "Results, table of included studies.", visual: true),
            self::ritem('prisma.14', 'Risque de biais (résultats)', "Les évaluations du risque de biais des études incluses sont-elles rapportées ?",
                "Résultats de l'évaluation du risque de biais pour chaque étude incluse.",
                "Results (risk of bias), RoB table/figure.", visual: true),
            self::ritem('prisma.15', 'Résultats de synthèse', "Les résultats des synthèses (effets agrégés, intervalles de confiance, hétérogénéité I²) sont-ils rapportés ?",
                "Pour chaque synthèse : résumé des résultats des études, estimation agrégée avec IC, mesure d'hétérogénéité (I²), le cas échéant forest plots.",
                "Results (synthesis of results), forest plots.", visual: true),
            self::ritem('prisma.16', 'Biais de reporting & certitude', "Les biais de reporting/publication et la certitude des preuves (ex. GRADE) sont-ils évalués ?",
                "Évaluation du risque de biais de publication par synthèse ET de la certitude/confiance dans les preuves (ex. GRADE).",
                "Methods/Results (reporting bias, certainty of evidence, GRADE)."),
            self::ritem('prisma.17', 'Limites', "Les limites des preuves incluses et du processus de revue sont-elles discutées ?",
                "Discussion des limites au niveau des preuves ET au niveau du processus de revue.",
                "Discussion (limitations)."),
            self::ritem('prisma.18', 'Enregistrement & financement', "L'enregistrement/protocole de la revue et les sources de financement sont-ils indiqués ?",
                "Numéro d'enregistrement et accès au protocole (ou mention d'absence), sources de financement et rôle des financeurs, déclaration de conflits.",
                "Registration and protocol, Funding, Declarations."),
        ];
    }
}
