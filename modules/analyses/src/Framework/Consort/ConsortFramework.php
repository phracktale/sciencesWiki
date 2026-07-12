<?php

declare(strict_types=1);

namespace Analyses\Framework\Consort;

use Analyses\Framework\FrameworkInterface;

/**
 * CONSORT — grille de REPORTING des essais randomisés contrôlés. Écrit from scratch.
 */
final class ConsortFramework implements FrameworkInterface
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
        ['id' => 'consort.01', 'dimension' => 'title_abstract', 'question' => "Le titre/résumé identifient-ils l'étude comme un essai randomisé, avec un résumé structuré ?"],
        ['id' => 'consort.02', 'dimension' => 'background', 'question' => "Le contexte scientifique et la justification sont-ils exposés ?"],
        ['id' => 'consort.03', 'dimension' => 'trial_design', 'question' => "Le plan de l'essai (parallèle, factoriel…) et le ratio d'allocation sont-ils décrits ?"],
        ['id' => 'consort.04', 'dimension' => 'participants', 'question' => "Les critères d'éligibilité et les lieux/cadre de collecte sont-ils décrits ?"],
        ['id' => 'consort.05', 'dimension' => 'interventions', 'question' => "Les interventions de chaque groupe sont-elles décrites de façon reproductible ?"],
        ['id' => 'consort.06', 'dimension' => 'outcomes', 'question' => "Les critères de jugement principaux/secondaires sont-ils définis (comment/quand mesurés) ?"],
        ['id' => 'consort.07', 'dimension' => 'sample_size', 'question' => "La détermination de la taille d'échantillon est-elle expliquée ?"],
        ['id' => 'consort.08', 'dimension' => 'randomisation_sequence', 'question' => "La génération de la séquence de randomisation est-elle décrite ?"],
        ['id' => 'consort.09', 'dimension' => 'allocation_concealment', 'question' => "Le mécanisme de dissimulation de l'allocation est-il décrit ?"],
        ['id' => 'consort.10', 'dimension' => 'implementation', 'question' => "Qui a généré la séquence, recruté, assigné les participants est-il précisé ?"],
        ['id' => 'consort.11', 'dimension' => 'blinding', 'question' => "L'aveugle (qui, comment) est-il décrit ?"],
        ['id' => 'consort.12', 'dimension' => 'statistical_methods', 'question' => "Les méthodes statistiques pour comparer les groupes sont-elles décrites ?"],
        ['id' => 'consort.13', 'dimension' => 'participant_flow', 'question' => "Le flux des participants (randomisés, traités, analysés, perdus) est-il rapporté ?"],
        ['id' => 'consort.14', 'dimension' => 'baseline', 'question' => "Les caractéristiques de base de chaque groupe sont-elles présentées ?"],
        ['id' => 'consort.15', 'dimension' => 'numbers_analysed', 'question' => "Le nombre analysé par groupe et l'analyse en intention de traiter sont-ils rapportés ?"],
        ['id' => 'consort.16', 'dimension' => 'outcomes_estimation', 'question' => "Pour chaque résultat : taille d'effet, précision (IC) sont-ils rapportés ?"],
        ['id' => 'consort.17', 'dimension' => 'harms', 'question' => "Les effets indésirables/nocivité sont-ils rapportés ?"],
        ['id' => 'consort.18', 'dimension' => 'limitations', 'question' => "Les limites et les sources potentielles de biais sont-elles discutées ?"],
        ['id' => 'consort.19', 'dimension' => 'registration', 'question' => "Le numéro d'enregistrement de l'essai et l'accès au protocole sont-ils fournis ?"],
        ['id' => 'consort.20', 'dimension' => 'funding', 'question' => "Les sources de financement et leur rôle sont-ils indiqués ?"],
    ];
}
