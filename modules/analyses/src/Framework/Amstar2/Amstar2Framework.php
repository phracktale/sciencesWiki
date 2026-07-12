<?php

declare(strict_types=1);

namespace Analyses\Framework\Amstar2;

use Analyses\Framework\FrameworkInterface;

/**
 * AMSTAR 2 (évaluation critique des revues systématiques) — 16 items. Échelle
 * yes / partial_yes / no (SPECS §8). Écrit from scratch pour le module.
 */
final class Amstar2Framework implements FrameworkInterface
{
    public function id(): string
    {
        return 'amstar2';
    }

    public function metadata(): array
    {
        return [
            'name' => 'AMSTAR 2',
            'version' => '1.0',
            'framework_type' => 'critical_appraisal',
            'supported_designs' => ['systematic_review', 'meta_analysis'],
            'supported_domains' => ['*'],
            'required_inputs' => ['full_text'],
            'dimensions' => ['methodological_quality'],
            'incompatibilities' => ['randomized_controlled_trial', 'cross_sectional'],
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
        ['id' => 'amstar2.q01', 'dimension' => 'pico', 'question' => "Les questions de recherche et critères d'inclusion incluent-ils les composantes PICO ?"],
        ['id' => 'amstar2.q02', 'dimension' => 'protocol', 'question' => "Le protocole était-il établi/enregistré AVANT la revue, et les écarts justifiés ?"],
        ['id' => 'amstar2.q03', 'dimension' => 'design_selection', 'question' => "Le choix des types d'études à inclure est-il expliqué ?"],
        ['id' => 'amstar2.q04', 'dimension' => 'search', 'question' => "La stratégie de recherche documentaire était-elle exhaustive ?"],
        ['id' => 'amstar2.q05', 'dimension' => 'selection_duplicate', 'question' => "La sélection des études a-t-elle été faite en double ?"],
        ['id' => 'amstar2.q06', 'dimension' => 'extraction_duplicate', 'question' => "L'extraction des données a-t-elle été faite en double ?"],
        ['id' => 'amstar2.q07', 'dimension' => 'excluded', 'question' => "Une liste des études exclues avec justifications est-elle fournie ?"],
        ['id' => 'amstar2.q08', 'dimension' => 'included_description', 'question' => "Les études incluses sont-elles décrites de façon suffisamment détaillée ?"],
        ['id' => 'amstar2.q09', 'dimension' => 'rob_tool', 'question' => "Une méthode satisfaisante d'évaluation du risque de biais des études incluses est-elle utilisée ?"],
        ['id' => 'amstar2.q10', 'dimension' => 'funding_included', 'question' => "Les sources de financement des études incluses sont-elles rapportées ?"],
        ['id' => 'amstar2.q11', 'dimension' => 'meta_methods', 'question' => "Les méthodes de méta-analyse (si réalisée) sont-elles appropriées ?"],
        ['id' => 'amstar2.q12', 'dimension' => 'rob_impact', 'question' => "L'impact du risque de biais sur les résultats de la synthèse est-il évalué ?"],
        ['id' => 'amstar2.q13', 'dimension' => 'rob_interpretation', 'question' => "Le risque de biais est-il pris en compte dans l'interprétation ?"],
        ['id' => 'amstar2.q14', 'dimension' => 'heterogeneity', 'question' => "L'hétérogénéité observée est-elle expliquée et discutée ?"],
        ['id' => 'amstar2.q15', 'dimension' => 'publication_bias', 'question' => "Le biais de publication est-il investigué et son impact discuté ?"],
        ['id' => 'amstar2.q16', 'dimension' => 'conflicts', 'question' => "Les conflits d'intérêts, y compris le financement de la revue, sont-ils déclarés ?"],
    ];
}
