<?php

declare(strict_types=1);

namespace Analyses\Framework\Mmat;

use Analyses\Framework\AbstractRichFramework;
use Analyses\Framework\FrameworkInterface;

/**
 * MMAT (Mixed Methods Appraisal Tool, Hong et al., 2018) — CALIBRÉ « riche » au niveau AXIS.
 * 2 questions de filtrage + catégorie « méthodes mixtes » (5 critères). Échelle yes / no /
 * cant_tell. Les critères mixtes présupposent que chaque composante (quali + quanti) respecte
 * aussi ses propres critères de qualité (critère q5).
 */
final class MmatFramework extends AbstractRichFramework implements FrameworkInterface
{
    public function id(): string
    {
        return 'mmat';
    }

    public function metadata(): array
    {
        return [
            'name' => 'MMAT',
            'version' => '2018',
            'framework_type' => 'critical_appraisal',
            'supported_designs' => ['mixed_methods', 'qualitative'],
            'supported_domains' => ['*'],
            'required_inputs' => ['full_text'],
            'dimensions' => ['methodological_quality', 'integration_coherence'],
            'incompatibilities' => [],
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
            Tu es un méthodologiste appliquant le MMAT (Mixed Methods Appraisal Tool, version 2018,
            Hong et al.) pour évaluer une étude à MÉTHODES MIXTES (combinaison de composantes
            qualitatives et quantitatives). Réponds d'abord aux deux questions de FILTRAGE, puis
            aux 5 critères de la catégorie « méthodes mixtes ». Pour chaque item : « yes »,
            « no » ou « cant_tell » (impossible de trancher avec le texte fourni). Le MMAT
            n'attribue pas de score global : chaque critère est jugé séparément.
            TXT;
    }

    public function applicabilityNote(): ?string
    {
        return "Le MMAT évalue des ÉTUDES EMPIRIQUES à méthodes mixtes (ou une composante qualitative). Si l'article est une revue systématique, un essai purement quantitatif, une étude purement descriptive sans volet qualitatif, ou un travail théorique/méthodologique, réponds \"applicable\": false.";
    }

    public function answerScale(): array
    {
        return [
            'yes' => 'le critère est clairement satisfait, adossé au texte.',
            'no' => "le critère est clairement NON satisfait (défaut méthodologique établi ou absence vérifiée d'un élément essentiel).",
            'cant_tell' => "impossible de trancher : l'information nécessaire est absente ou trop fragmentaire dans le texte fourni.",
        ];
    }

    public function unclearAnswer(): string
    {
        return 'cant_tell';
    }

    public function doctrine(): string
    {
        return "Les deux questions de filtrage (S1, S2) conditionnent la pertinence de l'évaluation : si l'une est « no », l'étude est difficilement appréciable et les 5 critères doivent être lus avec prudence. Un design à méthodes mixtes ne se résume pas à la coexistence de données quali et quanti : exige une véritable intégration.";
    }

    /** @return list<array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}> */
    public function richItems(): array
    {
        return [
            self::item(
                'mmat.s1', 'Filtrage',
                "Les questions de recherche sont-elles claires ?",
                "Question de filtrage MMAT S1 : sans question de recherche claire, l'appréciation méthodologique est peu fiable.",
                "Pour « yes » : une ou plusieurs questions/objectifs de recherche explicitement formulés.",
                [
                    'yes' => "questions ou objectifs de recherche explicitement énoncés.",
                    'no' => "aucune question/objectif identifiable.",
                    'cant_tell' => "formulation trop fragmentaire pour juger.",
                ],
                "Abstract, Introduction (objectives, research questions).",
            ),
            self::item(
                'mmat.s2', 'Filtrage',
                "Les données collectées permettent-elles de répondre aux questions de recherche ?",
                "Question de filtrage MMAT S2 : adéquation entre données recueillies et questions posées.",
                "Pour « yes » : les données recueillies (nature, sources) sont manifestement susceptibles de répondre aux questions.",
                [
                    'yes' => "les données collectées sont adéquates au regard des questions.",
                    'no' => "les données ne peuvent manifestement pas répondre aux questions posées.",
                    'cant_tell' => "lien données/questions impossible à établir avec le texte.",
                ],
                "Methods (data collection, sources), Abstract.",
            ),
            self::item(
                'mmat.q1', 'Méthodes mixtes — Justification',
                "Y a-t-il une justification adéquate de l'utilisation d'un design à méthodes mixtes ?",
                "Critère MMAT 5.1 : le recours au mixte doit répondre à un besoin (complémentarité, triangulation, développement, expansion).",
                "Pour « yes » : justification explicite du choix mixte et de sa plus-value pour la question posée.",
                [
                    'yes' => "le design mixte est explicitement justifié (raison + plus-value attendue).",
                    'no' => "aucune justification du recours au mixte, ou justification incohérente.",
                    'cant_tell' => "justification absente du texte fourni.",
                ],
                "Introduction, Methods (study design, rationale).",
            ),
            self::item(
                'mmat.q2', 'Méthodes mixtes — Intégration',
                "Les différentes composantes de l'étude sont-elles intégrées efficacement pour répondre à la question ?",
                "Critère MMAT 5.2 : intégration au niveau du design (séquentiel, concomitant…), de la collecte ou de l'analyse.",
                "Pour « yes » : intégration effective décrite (moment et mécanisme : jointure, comparaison, imbrication des volets quali et quanti).",
                [
                    'yes' => "intégration réelle et décrite des composantes (pas une simple juxtaposition).",
                    'no' => "volets quali et quanti menés en parallèle sans intégration.",
                    'cant_tell' => "modalités d'intégration non décrites.",
                ],
                "Methods (design, integration), Results (joint display, mixed analysis).",
                special: "La simple présence de données quali ET quanti n'est PAS une intégration : exige une articulation explicite.",
            ),
            self::item(
                'mmat.q3', 'Méthodes mixtes — Interprétation',
                "Les résultats de l'intégration des composantes qualitatives et quantitatives sont-ils correctement interprétés ?",
                "Critère MMAT 5.3 : l'interprétation doit exploiter la valeur ajoutée de l'intégration (méta-inférences).",
                "Pour « yes » : interprétation conjointe des volets, produisant des inférences que ni le quali ni le quanti seul ne fournirait.",
                [
                    'yes' => "interprétation intégrée produisant des méta-inférences cohérentes.",
                    'no' => "interprétations juxtaposées sans exploitation de l'intégration.",
                    'cant_tell' => "interprétation de l'intégration absente ou trop vague.",
                ],
                "Results, Discussion (integrated findings, meta-inferences).",
            ),
            self::item(
                'mmat.q4', 'Méthodes mixtes — Divergences',
                "Les divergences et incohérences entre résultats quantitatifs et qualitatifs sont-elles traitées de façon adéquate ?",
                "Critère MMAT 5.4 : les contradictions entre volets doivent être explicitées et discutées, pas masquées.",
                "Pour « yes » : les divergences éventuelles sont identifiées ET discutées (explication, mise en perspective) ; ou absence de divergence explicitement constatée.",
                [
                    'yes' => "divergences identifiées et discutées (ou convergence explicitement établie).",
                    'no' => "contradictions apparentes ignorées ou passées sous silence.",
                    'cant_tell' => "impossible de savoir si des divergences existaient ou comment elles ont été traitées.",
                ],
                "Results, Discussion (divergence, inconsistency, convergence).",
            ),
            self::item(
                'mmat.q5', 'Méthodes mixtes — Qualité des composantes',
                "Chaque composante respecte-t-elle les critères de qualité propres à sa tradition (quantitative ET qualitative) ?",
                "Critère MMAT 5.5 : le volet quali doit respecter les critères qualitatifs (cohérence, ancrage des données) et le volet quanti les siens (échantillonnage, mesure, biais).",
                "Pour « yes » : chaque composante satisfait les standards de qualité de sa méthode (rigueur qualitative ET validité quantitative).",
                [
                    'yes' => "les deux composantes respectent leurs critères de qualité respectifs.",
                    'no' => "au moins une composante présente un défaut de qualité méthodologique établi.",
                    'cant_tell' => "qualité d'au moins une composante impossible à évaluer avec le texte.",
                ],
                "Methods et Results des deux volets (sampling, measurement, data analysis, rigueur qualitative).",
                special: "Une faiblesse marquée d'un seul volet suffit à répondre « no » à cet item.",
            ),
        ];
    }
}
