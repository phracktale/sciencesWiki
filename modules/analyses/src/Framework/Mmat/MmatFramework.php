<?php

declare(strict_types=1);

namespace Analyses\Framework\Mmat;

use Analyses\Framework\FrameworkInterface;

/**
 * MMAT (Mixed Methods Appraisal Tool) — 2 questions de filtrage + 5 critères « méthodes
 * mixtes ». Échelle yes / no / cant_tell (SPECS §8). Écrit from scratch pour le module.
 */
final class MmatFramework implements FrameworkInterface
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
        ['id' => 'mmat.s1', 'dimension' => 'screening', 'question' => "Filtrage — Les questions de recherche sont-elles claires ?"],
        ['id' => 'mmat.s2', 'dimension' => 'screening', 'question' => "Filtrage — Les données collectées permettent-elles de répondre aux questions de recherche ?"],
        ['id' => 'mmat.q1', 'dimension' => 'rationale', 'question' => "Y a-t-il une justification adéquate de l'utilisation d'un design à méthodes mixtes ?"],
        ['id' => 'mmat.q2', 'dimension' => 'integration', 'question' => "Les différentes composantes de l'étude sont-elles intégrées efficacement pour répondre à la question ?"],
        ['id' => 'mmat.q3', 'dimension' => 'outputs', 'question' => "Les résultats de l'intégration des composantes qualitatives et quantitatives sont-ils correctement interprétés ?"],
        ['id' => 'mmat.q4', 'dimension' => 'divergences', 'question' => "Les divergences et incohérences entre résultats quantitatifs et qualitatifs sont-elles traitées de façon adéquate ?"],
        ['id' => 'mmat.q5', 'dimension' => 'component_quality', 'question' => "Les différentes composantes respectent-elles les critères de qualité propres à chaque tradition (quantitative et qualitative) ?"],
    ];
}
