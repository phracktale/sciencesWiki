<?php

declare(strict_types=1);

namespace Analyses\Framework\Consort;

use Analyses\Framework\AbstractReportingRichFramework;

/**
 * CONSORT (Consolidated Standards of Reporting Trials, 2010) — grille de REPORTING des ESSAIS
 * RANDOMISÉS, CALIBRÉE « riche » au niveau AXIS. Les 25 items CONSORT sont regroupés en 20
 * rubriques ; on évalue la qualité de DESCRIPTION, pas la validité.
 */
final class ConsortFramework extends AbstractReportingRichFramework
{
    public function id(): string
    {
        return 'consort';
    }

    public function metadata(): array
    {
        return [
            'name' => 'CONSORT',
            'version' => '2010',
            'framework_type' => 'reporting_guideline',
            'supported_designs' => ['randomized_controlled_trial', 'cluster_randomized_trial', 'crossover_trial'],
            'supported_domains' => ['*'],
            'required_inputs' => ['full_text'],
            'dimensions' => ['reporting_quality'],
            'incompatibilities' => ['systematic_review', 'cross_sectional'],
            'criteria_count' => \count($this->richItems()),
        ];
    }

    public function toolIntro(): string
    {
        return <<<TXT
            Tu es un éditeur scientifique appliquant la grille de reporting CONSORT 2010 à un ESSAI
            CONTRÔLÉ RANDOMISÉ. Pour chaque item, juge si l'élément attendu est RAPPORTÉ :
            « reported », « partially_reported », « not_reported » ou « unclear ». Tu évalues la
            qualité de DESCRIPTION du compte rendu, pas la validité de l'essai.
            TXT;
    }

    /** @return list<array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}> */
    public function richItems(): array
    {
        return [
            self::ritem('consort.01', 'Titre & résumé', "Le titre/résumé identifient-ils l'étude comme un essai randomisé, avec un résumé structuré ?",
                "Le mot « randomisé » dans le titre ET un résumé structuré (design, méthodes, résultats, conclusions).",
                "Title, Abstract."),
            self::ritem('consort.02', 'Contexte', "Le contexte scientifique et la justification de l'essai sont-ils exposés ?",
                "État des connaissances et justification de l'essai.",
                "Introduction (background, rationale)."),
            self::ritem('consort.03', "Plan de l'essai", "Le plan de l'essai (parallèle, factoriel…) et le ratio d'allocation sont-ils décrits, y compris les changements après début ?",
                "Type de plan (groupes parallèles, factoriel, cross-over), ratio d'allocation, et tout changement important au protocole après le début.",
                "Methods (trial design)."),
            self::ritem('consort.04', 'Participants', "Les critères d'éligibilité des participants et les lieux/cadre de collecte sont-ils décrits ?",
                "Critères d'inclusion/exclusion ET cadres et lieux de recueil des données.",
                "Methods (participants, settings)."),
            self::ritem('consort.05', 'Interventions', "Les interventions de chaque groupe sont-elles décrites de façon suffisamment détaillée pour être reproduites ?",
                "Détail des interventions par groupe (comment et quand administrées), reproductible.",
                "Methods (interventions)."),
            self::ritem('consort.06', 'Critères de jugement', "Les critères de jugement principaux et secondaires sont-ils complètement définis (comment et quand mesurés) ?",
                "Définition précise des outcomes primaires/secondaires, méthode et moment de mesure ; tout changement après début d'essai.",
                "Methods (outcomes)."),
            self::ritem('consort.07', "Taille d'échantillon", "La détermination de la taille d'échantillon est-elle expliquée (et règles d'arrêt le cas échéant) ?",
                "Calcul de la taille d'échantillon (hypothèse, puissance) et, le cas échéant, règles d'arrêt des analyses intermédiaires.",
                "Methods (sample size)."),
            self::ritem('consort.08', 'Séquence de randomisation', "La génération de la séquence de randomisation (méthode, restrictions type blocs/stratification) est-elle décrite ?",
                "Méthode de génération de la séquence aléatoire et détails des restrictions (blocs, stratification).",
                "Methods (randomisation — sequence generation)."),
            self::ritem('consort.09', "Dissimulation de l'allocation", "Le mécanisme de dissimulation de l'allocation (jusqu'à l'assignation) est-il décrit ?",
                "Mécanisme d'implémentation de l'allocation (ex. enveloppes opaques numérotées, allocation centralisée) préservant le secret jusqu'à l'assignation.",
                "Methods (allocation concealment mechanism)."),
            self::ritem('consort.10', 'Implémentation', "Est-il précisé qui a généré la séquence, recruté les participants et assigné aux interventions ?",
                "Attribution des rôles : génération de la séquence, inscription des participants, assignation aux groupes.",
                "Methods (implementation)."),
            self::ritem('consort.11', 'Aveugle', "L'aveugle (qui était en aveugle : participants, soignants, évaluateurs, et comment) est-il décrit ?",
                "Qui était en aveugle après assignation et comment ; à défaut, mention explicite de l'ouvert.",
                "Methods (blinding).", na: true),
            self::ritem('consort.12', 'Méthodes statistiques', "Les méthodes statistiques pour comparer les groupes sur les critères principaux/secondaires sont-elles décrites ?",
                "Méthodes de comparaison des groupes et méthodes des analyses additionnelles (sous-groupes, ajustements).",
                "Methods (statistical methods)."),
            self::ritem('consort.13', 'Flux des participants', "Le flux des participants (randomisés, recevant le traitement, analysés, perdus/exclus avec raisons) est-il rapporté, idéalement par un diagramme ?",
                "Pour chaque groupe : nombres randomisés, ayant reçu le traitement, analysés pour le critère principal, perdus/exclus avec raisons — idéalement un diagramme CONSORT.",
                "Results, CONSORT flow diagram.", visual: true),
            self::ritem('consort.14', 'Caractéristiques de base', "Les caractéristiques démographiques et cliniques de base de chaque groupe sont-elles présentées ?",
                "Tableau des caractéristiques initiales par groupe, ET dates de recrutement/suivi.",
                "Results, Table 1 (baseline).", visual: true),
            self::ritem('consort.15', 'Effectifs analysés', "Le nombre de participants analysés par groupe et le respect de l'intention de traiter sont-ils rapportés ?",
                "Effectifs analysés par groupe pour chaque critère et si l'analyse est en intention de traiter (par groupe assigné).",
                "Results (numbers analysed).", visual: true),
            self::ritem('consort.16', 'Résultats & estimation', "Pour chaque critère, la taille d'effet et sa précision (intervalle de confiance) sont-elles rapportées, par groupe ?",
                "Résultats par groupe, taille d'effet estimée et précision (IC 95 %) pour les critères primaires et secondaires.",
                "Results (outcomes and estimation), tables.", visual: true),
            self::ritem('consort.17', 'Effets indésirables', "Les effets indésirables / la nocivité dans chaque groupe sont-ils rapportés ?",
                "Tous les préjudices importants ou effets indésirables par groupe (ou mention explicite de leur absence/surveillance).",
                "Results (harms), safety."),
            self::ritem('consort.18', 'Limites', "Les limites de l'essai (sources potentielles de biais, imprécision, multiplicité) sont-elles discutées ?",
                "Discussion des limites : biais potentiels, imprécision, analyses multiples.",
                "Discussion (limitations)."),
            self::ritem('consort.19', 'Enregistrement & protocole', "Le numéro d'enregistrement de l'essai et l'accès au protocole complet sont-ils fournis ?",
                "Numéro d'enregistrement (ex. ClinicalTrials.gov) ET indication d'où obtenir le protocole complet.",
                "Registration, protocol availability, Methods."),
            self::ritem('consort.20', 'Financement', "Les sources de financement et le rôle des financeurs sont-ils indiqués ?",
                "Source(s) de financement ET rôle des financeurs dans l'essai.",
                "Funding, Declarations."),
        ];
    }
}
