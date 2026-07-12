<?php

declare(strict_types=1);

namespace Analyses\Framework\Rob2;

use Analyses\Framework\FrameworkInterface;

/**
 * RoB 2 (Cochrane Risk of Bias, essais randomisés) — 5 domaines. Échelle de jugement
 * par domaine : low / some_concerns / high (SPECS §8). Écrit from scratch pour le module.
 */
final class Rob2Framework implements FrameworkInterface
{
    public function id(): string
    {
        return 'rob2';
    }

    public function metadata(): array
    {
        return [
            'name' => 'RoB 2',
            'version' => '1.0',
            'framework_type' => 'risk_of_bias',
            'supported_designs' => ['randomized_controlled_trial', 'cluster_randomized_trial', 'crossover_trial'],
            'supported_domains' => ['*'],
            'required_inputs' => ['full_text'],
            'dimensions' => ['risk_of_bias'],
            'incompatibilities' => ['systematic_review', 'meta_analysis'],
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
        ['id' => 'rob2.d1', 'dimension' => 'randomization', 'question' => "Domaine 1 — Biais lié au processus de randomisation (séquence aléatoire, assignation dissimulée, comparabilité initiale) ?"],
        ['id' => 'rob2.d2', 'dimension' => 'deviations', 'question' => "Domaine 2 — Biais dû aux écarts par rapport aux interventions prévues (aveugle, adhérence, analyse ITT) ?"],
        ['id' => 'rob2.d3', 'dimension' => 'missing_data', 'question' => "Domaine 3 — Biais dû aux données de résultat manquantes (complétude, différentiel entre groupes) ?"],
        ['id' => 'rob2.d4', 'dimension' => 'outcome_measurement', 'question' => "Domaine 4 — Biais de mesure du résultat (méthode, aveugle des évaluateurs, influence de la connaissance du groupe) ?"],
        ['id' => 'rob2.d5', 'dimension' => 'reported_result', 'question' => "Domaine 5 — Biais de sélection du résultat rapporté (plan pré-enregistré, sélection parmi analyses/sous-groupes) ?"],
    ];
}
