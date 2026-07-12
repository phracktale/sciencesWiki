<?php

declare(strict_types=1);

namespace Analyses\Framework\Strobe;

use Analyses\Framework\FrameworkInterface;

/**
 * STROBE — grille de REPORTING des études observationnelles (transversales, cohortes,
 * cas-témoins). 22 items. Évalue la qualité de description, pas la validité. Écrit from
 * scratch pour le module.
 */
final class StrobeFramework implements FrameworkInterface
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
            'criteria_count' => \count(self::CRITERIA),
        ];
    }

    /** @return list<array{id: string, dimension: string, question: string}> */
    public function criteria(): array
    {
        return self::CRITERIA;
    }

    /** @var list<array{id: string, dimension: string, question: string}> */
    private const CRITERIA = [
        ['id' => 'strobe.01', 'dimension' => 'title_abstract', 'question' => "Le titre/résumé indiquent-ils le plan d'étude et résument-ils ce qui a été fait/trouvé ?"],
        ['id' => 'strobe.02', 'dimension' => 'background', 'question' => "Le contexte scientifique et la justification sont-ils expliqués ?"],
        ['id' => 'strobe.03', 'dimension' => 'objectives', 'question' => "Les objectifs/hypothèses spécifiques sont-ils énoncés ?"],
        ['id' => 'strobe.04', 'dimension' => 'design', 'question' => "Les éléments clés du plan d'étude sont-ils présentés tôt dans l'article ?"],
        ['id' => 'strobe.05', 'dimension' => 'setting', 'question' => "Le cadre, les lieux et les dates (recrutement, exposition, suivi, collecte) sont-ils décrits ?"],
        ['id' => 'strobe.06', 'dimension' => 'participants', 'question' => "Les critères d'éligibilité, sources et méthodes de sélection des participants sont-ils décrits ?"],
        ['id' => 'strobe.07', 'dimension' => 'variables', 'question' => "Les résultats, expositions, prédicteurs, facteurs de confusion sont-ils clairement définis ?"],
        ['id' => 'strobe.08', 'dimension' => 'measurement', 'question' => "Les sources de données et méthodes de mesure sont-elles décrites pour chaque variable ?"],
        ['id' => 'strobe.09', 'dimension' => 'bias', 'question' => "Les efforts pour traiter les sources potentielles de biais sont-ils décrits ?"],
        ['id' => 'strobe.10', 'dimension' => 'study_size', 'question' => "La façon dont la taille d'étude a été déterminée est-elle expliquée ?"],
        ['id' => 'strobe.11', 'dimension' => 'quantitative', 'question' => "Le traitement des variables quantitatives (groupements) est-il expliqué ?"],
        ['id' => 'strobe.12', 'dimension' => 'statistical_methods', 'question' => "Les méthodes statistiques (confusion, sous-groupes, données manquantes, sensibilité) sont-elles décrites ?"],
        ['id' => 'strobe.13', 'dimension' => 'participants_flow', 'question' => "Le nombre de participants à chaque étape est-il rapporté (éligibles, inclus, analysés) ?"],
        ['id' => 'strobe.14', 'dimension' => 'descriptive', 'question' => "Les caractéristiques des participants et les données manquantes sont-elles décrites ?"],
        ['id' => 'strobe.15', 'dimension' => 'outcome_data', 'question' => "Les données de résultat (nombres d'événements/mesures) sont-elles rapportées ?"],
        ['id' => 'strobe.16', 'dimension' => 'main_results', 'question' => "Les résultats principaux (estimations, IC, ajustement des confondants) sont-ils rapportés ?"],
        ['id' => 'strobe.17', 'dimension' => 'other_analyses', 'question' => "Les autres analyses (sous-groupes, interactions, sensibilité) sont-elles rapportées ?"],
        ['id' => 'strobe.18', 'dimension' => 'key_results', 'question' => "Les résultats clés sont-ils résumés au regard des objectifs ?"],
        ['id' => 'strobe.19', 'dimension' => 'limitations', 'question' => "Les limites (sources de biais/imprécision) sont-elles discutées ?"],
        ['id' => 'strobe.20', 'dimension' => 'interpretation', 'question' => "L'interprétation est-elle prudente (causalité, multiplicité) ?"],
        ['id' => 'strobe.21', 'dimension' => 'generalisability', 'question' => "La généralisabilité (validité externe) est-elle discutée ?"],
        ['id' => 'strobe.22', 'dimension' => 'funding', 'question' => "Le financement et le rôle des financeurs sont-ils indiqués ?"],
    ];
}
