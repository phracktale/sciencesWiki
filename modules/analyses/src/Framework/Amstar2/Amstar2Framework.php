<?php

declare(strict_types=1);

namespace Analyses\Framework\Amstar2;

use Analyses\Framework\AbstractRichFramework;
use Analyses\Framework\FrameworkInterface;

/**
 * AMSTAR 2 (évaluation critique des revues systématiques, Shea et al., BMJ 2017) — CALIBRÉ
 * « riche » au niveau AXIS. 16 items, échelle yes / partial_yes / no, avec cadrage par item et
 * signalement des 7 domaines CRITIQUES (2, 4, 7, 9, 11, 13, 15) qui déterminent la confiance globale.
 */
final class Amstar2Framework extends AbstractRichFramework implements FrameworkInterface
{
    /** Items critiques AMSTAR 2 (un défaut y pèse lourdement sur la confiance globale). */
    private const CRITICAL = ['amstar2.q02', 'amstar2.q04', 'amstar2.q07', 'amstar2.q09', 'amstar2.q11', 'amstar2.q13', 'amstar2.q15'];

    public function id(): string
    {
        return 'amstar2';
    }

    public function metadata(): array
    {
        return [
            'name' => 'AMSTAR 2',
            'version' => '2.0',
            'framework_type' => 'critical_appraisal',
            'supported_designs' => ['systematic_review', 'meta_analysis'],
            'supported_domains' => ['*'],
            'required_inputs' => ['full_text'],
            'dimensions' => ['methodological_quality'],
            'incompatibilities' => ['randomized_controlled_trial', 'cross_sectional'],
            'criteria_count' => \count($this->richItems()),
        ];
    }

    /** @return list<array{id: string, dimension: string, question: string}> */
    public function criteria(): array
    {
        return array_map(
            static fn (array $it): array => ['id' => $it['id'], 'dimension' => $it['section'], 'question' => $it['question']],
            $this->richItems(),
        );
    }

    public function toolIntro(): string
    {
        return <<<TXT
            Tu es un méthodologiste appliquant AMSTAR 2 (A MeaSurement Tool to Assess systematic Reviews 2,
            Shea et al., BMJ 2017) pour évaluer la QUALITÉ MÉTHODOLOGIQUE d'une REVUE SYSTÉMATIQUE ou
            MÉTA-ANALYSE. Pour chaque item : « yes » (critère pleinement satisfait), « partial_yes »
            (partiellement satisfait, seuil minimal atteint) ou « no ». Sept items sont CRITIQUES
            (2, 4, 7, 9, 11, 13, 15) : un défaut sur ces items dégrade fortement la confiance globale.
            TXT;
    }

    public function applicabilityNote(): ?string
    {
        return "AMSTAR 2 ne s'applique qu'aux REVUES SYSTÉMATIQUES et MÉTA-ANALYSES. Si l'article est une étude primaire (essai, cohorte, transversale…) ou une revue narrative sans méthode systématique, réponds \"applicable\": false.";
    }

    public function answerScale(): array
    {
        return [
            'yes' => 'critère pleinement satisfait (tous les éléments attendus sont explicitement rapportés).',
            'partial_yes' => 'critère partiellement satisfait — le seuil minimal AMSTAR 2 est atteint, mais pas la totalité des éléments.',
            'no' => 'critère non satisfait (élément essentiel absent de façon vérifiée, ou clairement non conforme).',
            'unclear' => 'information insuffisante dans le texte fourni pour juger.',
        ];
    }

    public function unclearAnswer(): string
    {
        return 'unclear';
    }

    public function doctrine(): string
    {
        return "Rappelle-toi que la confiance globale AMSTAR 2 se construit à partir des faiblesses, en pondérant fortement les 7 items CRITIQUES. Ne coche « yes » que si TOUS les éléments de l'attendu sont rapportés ; s'il en manque au seuil minimal, c'est « partial_yes ».";
    }

    /** @return list<array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}> */
    public function richItems(): array
    {
        $items = [
            self::item(
                'amstar2.q01', 'Q1 — Composantes PICO',
                "Les questions de recherche et les critères d'inclusion incluent-ils les composantes PICO ?",
                "PICO = Population, Intervention, Comparateur, Outcome (résultat).",
                "Pour « yes » : population, intervention, comparateur et résultats explicitement définis (optionnel : horizon temporel, cadre).",
                [
                    'yes' => "les 4 composantes PICO sont explicitement présentes dans la question et/ou les critères d'inclusion.",
                    'partial_yes' => "la plupart des composantes sont présentes mais l'une (souvent le comparateur) est implicite.",
                    'no' => "question vague, sans population/intervention/résultats clairement définis.",
                ],
                "Abstract, Introduction (objectives), Methods (eligibility/inclusion criteria).",
            ),
            self::item(
                'amstar2.q02', 'Q2 — Protocole a priori',
                "Le protocole était-il établi/enregistré AVANT la conduite de la revue, et les écarts justifiés ?",
                "Item CRITIQUE. Un protocole a priori (PROSPERO, publication de protocole) limite les décisions post-hoc.",
                "Pour « yes » : protocole enregistré ou publié AVANT la revue, couvrant question, critères, méthodes de synthèse, ET justification des éventuels écarts.",
                [
                    'yes' => "protocole a priori explicite (numéro PROSPERO / protocole publié) ET écarts au protocole justifiés.",
                    'partial_yes' => "une déclaration écrite indique qu'un protocole existait, avec question, critères et synthèse, mais sans enregistrement complet ou sans justification des écarts.",
                    'no' => "aucun protocole a priori mentionné.",
                ],
                "Methods (protocol, registration), PROSPERO/registration number, Abstract.",
                special: "Ne confonds pas « enregistré » et « publié après coup ». Un numéro PROSPERO cité est un fort indice de « yes ».",
            ),
            self::item(
                'amstar2.q03', 'Q3 — Choix des designs',
                "Le choix des types d'études à inclure est-il expliqué ?",
                "La revue doit justifier l'inclusion (ou l'exclusion) des essais randomisés et/ou des études non randomisées.",
                "Pour « yes » : justification explicite du choix des designs inclus (ex. « seuls les ECR », ou « ECR + études observationnelles car… »).",
                [
                    'yes' => "le choix des designs est explicitement justifié.",
                    'partial_yes' => "les designs inclus sont indiqués mais la justification est minimale.",
                    'no' => "aucune explication du choix des designs.",
                ],
                "Methods (eligibility criteria, study designs), Introduction.",
            ),
            self::item(
                'amstar2.q04', 'Q4 — Recherche exhaustive',
                "La stratégie de recherche documentaire était-elle exhaustive ?",
                "Item CRITIQUE. Une recherche exhaustive limite le biais de sélection des études.",
                "Pour « yes » : ≥2 bases interrogées avec mots-clés/équation fournis, recherche des références des études incluses, consultation de la littérature grise/registres d'essais, experts/fabricants consultés, recherche récente (< 24 mois avant publication).",
                [
                    'yes' => "≥2 bases + équation de recherche + littérature grise/registres + références croisées, recherche récente.",
                    'partial_yes' => "≥2 bases interrogées ET mots-clés/équation fournis (seuil minimal), sans tous les compléments.",
                    'no' => "une seule base, ou stratégie non documentée.",
                ],
                "Methods (search strategy, databases, keywords, grey literature), Appendix/Supplementary (full search string).",
                visual: true,
                special: "Le seuil « partial_yes » exige au minimum deux bases ET la présentation des mots-clés/équation.",
            ),
            self::item(
                'amstar2.q05', 'Q5 — Sélection en double',
                "La sélection des études a-t-elle été réalisée en double (deux relecteurs indépendants) ?",
                "Deux relecteurs sélectionnant indépendamment réduisent les erreurs d'inclusion.",
                "Pour « yes » : ≥2 relecteurs indépendants pour la sélection, avec consensus (ou un relecteur vérifiant un échantillon avec bon accord).",
                [
                    'yes' => "sélection par ≥2 relecteurs indépendants avec procédure de consensus explicite.",
                    'partial_yes' => "double sélection mentionnée mais procédure de résolution des désaccords non décrite.",
                    'no' => "sélection par une seule personne, ou non décrite.",
                ],
                "Methods (study selection, screening, reviewers, consensus).",
            ),
            self::item(
                'amstar2.q06', 'Q6 — Extraction en double',
                "L'extraction des données a-t-elle été réalisée en double ?",
                "Deux extracteurs réduisent les erreurs de transcription des données.",
                "Pour « yes » : extraction par ≥2 relecteurs (ou un extrait, un vérifie), avec accord.",
                [
                    'yes' => "extraction des données par ≥2 relecteurs indépendants (ou vérification complète par un second).",
                    'partial_yes' => "double extraction mentionnée sans détail sur la résolution des écarts.",
                    'no' => "extraction par une seule personne, ou non décrite.",
                ],
                "Methods (data extraction, reviewers).",
            ),
            self::item(
                'amstar2.q07', 'Q7 — Études exclues justifiées',
                "Une liste des études exclues (lues en texte intégral) avec justification est-elle fournie ?",
                "Item CRITIQUE. La transparence sur les exclusions permet de vérifier l'absence de sélection biaisée.",
                "Pour « yes » : liste des études exclues au stade texte intégral ET justification de chaque exclusion.",
                [
                    'yes' => "liste des études exclues en texte intégral ET raison de chaque exclusion.",
                    'partial_yes' => "une liste des études exclues est fournie (seuil minimal) mais sans justification systématique.",
                    'no' => "aucune liste des études exclues.",
                ],
                "Results (study selection), PRISMA flow diagram, Supplementary (excluded studies table).",
                visual: true,
            ),
            self::item(
                'amstar2.q08', 'Q8 — Description des études incluses',
                "Les études incluses sont-elles décrites de façon suffisamment détaillée ?",
                "Populations, interventions, comparateurs, résultats, designs, cadres doivent être décrits.",
                "Pour « yes » : description des populations, interventions, comparateurs, résultats, designs ET cadre/financement des études incluses.",
                [
                    'yes' => "description détaillée de tous les éléments (PICO + design + cadre) pour les études incluses.",
                    'partial_yes' => "population, intervention, comparateur, résultats et design décrits (seuil minimal) mais éléments contextuels manquants.",
                    'no' => "description trop sommaire des études incluses.",
                ],
                "Results, Table of included studies / characteristics table, Supplementary.",
                visual: true,
            ),
            self::item(
                'amstar2.q09', 'Q9 — Risque de biais des études',
                "Une méthode satisfaisante d'évaluation du risque de biais des études incluses est-elle utilisée ?",
                "Item CRITIQUE. Une évaluation du RoB adaptée au design conditionne l'interprétation.",
                "Pour « yes » : évaluation du risque de biais avec un outil adapté au design (RoB 2 pour ECR, ROBINS-I / échelle adaptée pour non randomisées), couvrant les biais pertinents (randomisation, allocation, données manquantes, mesure, sélection du résultat).",
                [
                    'yes' => "outil de RoB adapté, appliqué à toutes les études, couvrant les domaines essentiels.",
                    'partial_yes' => "RoB évalué (seuil minimal : allocation/dissimulation pour ECR, ou confusion/sélection pour non randomisées) mais couverture incomplète.",
                    'no' => "aucune évaluation du risque de biais, ou outil manifestement inadapté.",
                ],
                "Methods (risk of bias / quality assessment tool), Results (RoB table), Supplementary.",
                visual: true,
            ),
            self::item(
                'amstar2.q10', 'Q10 — Financement des études incluses',
                "Les sources de financement des études incluses sont-elles rapportées ?",
                "Le financement des études primaires peut influencer leurs résultats.",
                "Pour « yes » : la revue rapporte (ou déclare avoir cherché) les sources de financement de chaque étude incluse.",
                [
                    'yes' => "sources de financement des études incluses rapportées (ou absence explicitement notée après recherche).",
                    'partial_yes' => "financement rapporté pour une partie seulement des études.",
                    'no' => "financement des études incluses non abordé.",
                ],
                "Results, characteristics table, Supplementary.",
            ),
            self::item(
                'amstar2.q11', 'Q11 — Méthodes de méta-analyse',
                "Les méthodes de combinaison statistique (méta-analyse), si réalisée, étaient-elles appropriées ?",
                "Item CRITIQUE (si méta-analyse). Modèle, pondération, hétérogénéité doivent être adaptés.",
                "Pour « yes » : justification du modèle (effets fixes/aléatoires), méthode de pondération adaptée, prise en compte de l'hétérogénéité, ajustements pertinents ; pour les non randomisées, combinaison des estimations ajustées.",
                [
                    'yes' => "méthode de synthèse quantitative justifiée et adaptée (modèle, hétérogénéité, ajustement).",
                    'partial_yes' => "méta-analyse réalisée avec méthode standard mais justification partielle du modèle ou de l'hétérogénéité.",
                    'no' => "méthodes de combinaison inappropriées (ex. vote-counting, agrégation naïve).",
                ],
                "Methods (statistical synthesis, meta-analysis model), Results (forest plots, I²).",
                na: true,
                visual: true,
                special: "Réponds « na » uniquement si AUCUNE synthèse quantitative n'est réalisée (revue purement narrative).",
            ),
            self::item(
                'amstar2.q12', 'Q12 — Impact du RoB sur la synthèse',
                "L'impact du risque de biais sur les résultats de la méta-analyse/synthèse est-il évalué ?",
                "Analyses de sensibilité restreintes aux études à faible risque de biais, par ex.",
                "Pour « yes » : la revue analyse l'effet du risque de biais sur les résultats agrégés (ex. sensibilité aux études à haut RoB, méta-régression).",
                [
                    'yes' => "impact du RoB sur la synthèse explicitement évalué (analyse de sensibilité/sous-groupes par RoB).",
                    'partial_yes' => "impact évoqué qualitativement sans analyse dédiée.",
                    'no' => "impact du RoB sur les résultats non évalué.",
                ],
                "Methods/Results (sensitivity analysis, subgroup by risk of bias), Discussion.",
                na: true,
                special: "« na » si aucune synthèse quantitative n'a été réalisée.",
            ),
            self::item(
                'amstar2.q13', "Q13 — RoB dans l'interprétation",
                "Le risque de biais des études incluses est-il pris en compte dans l'interprétation/discussion des résultats ?",
                "Item CRITIQUE. Les conclusions doivent être nuancées selon la qualité des preuves.",
                "Pour « yes » : la discussion pondère explicitement les conclusions par le risque de biais des études incluses.",
                [
                    'yes' => "l'interprétation tient compte explicitement du risque de biais.",
                    'partial_yes' => "le RoB est mentionné en discussion sans réellement moduler les conclusions.",
                    'no' => "conclusions tirées sans référence au risque de biais.",
                ],
                "Discussion, Conclusion, limitations, GRADE / certainty of evidence.",
            ),
            self::item(
                'amstar2.q14', 'Q14 — Hétérogénéité expliquée',
                "L'hétérogénéité observée dans les résultats est-elle expliquée et discutée ?",
                "Sources d'hétérogénéité (clinique, méthodologique, statistique) explorées.",
                "Pour « yes » : la revue discute les causes possibles de l'hétérogénéité (ou justifie son absence) et l'explore (sous-groupes, méta-régression).",
                [
                    'yes' => "hétérogénéité quantifiée ET ses sources discutées/explorées.",
                    'partial_yes' => "hétérogénéité signalée (I², test) mais peu discutée quant à ses causes.",
                    'no' => "hétérogénéité ignorée alors que présente.",
                ],
                "Results (I², heterogeneity test, subgroup analyses), Discussion.",
            ),
            self::item(
                'amstar2.q15', 'Q15 — Biais de publication',
                "Le biais de publication (petit échantillon) a-t-il été investigué et son impact discuté ?",
                "Item CRITIQUE (si méta-analyse). Funnel plot, tests d'asymétrie.",
                "Pour « yes » : investigation graphique/statistique du biais de publication (funnel plot, test d'Egger…) ET discussion de son impact probable.",
                [
                    'yes' => "biais de publication investigué (funnel plot / test) ET impact discuté.",
                    'partial_yes' => "biais de publication évoqué qualitativement sans test.",
                    'no' => "biais de publication non abordé alors qu'une synthèse quantitative le permettrait.",
                ],
                "Methods/Results (publication bias, funnel plot, Egger test), Discussion.",
                na: true,
                visual: true,
                special: "« na » si trop peu d'études (< 10) ou pas de méta-analyse — mais alors mentionne-le.",
            ),
            self::item(
                'amstar2.q16', "Q16 — Conflits d'intérêts",
                "Les conflits d'intérêts, y compris les sources de financement de la revue elle-même, sont-ils déclarés ?",
                "Déclaration des CoI des auteurs de la revue et du financement de la revue.",
                "Pour « yes » : déclaration des conflits d'intérêts des auteurs ET du financement de la revue (ou absence explicite de financement).",
                [
                    'yes' => "conflits d'intérêts ET financement de la revue déclarés (ou absence explicitement indiquée).",
                    'partial_yes' => "déclaration partielle (conflits sans financement, ou inversement).",
                    'no' => "aucune déclaration de conflits ni de financement.",
                ],
                "Declarations (competing interests, funding), Acknowledgements, title page.",
                special: "Ne confonds pas « les auteurs déclarent n'avoir aucun conflit » (une déclaration ⇒ favorable) avec l'ABSENCE de toute déclaration.",
            ),
        ];

        // Préfixe visuel des items critiques (aide au relecteur et au LLM).
        return array_map(function (array $it): array {
            if (\in_array($it['id'], self::CRITICAL, true)) {
                $it['question'] = '[CRITIQUE] '.$it['question'];
            }

            return $it;
        }, $items);
    }
}
