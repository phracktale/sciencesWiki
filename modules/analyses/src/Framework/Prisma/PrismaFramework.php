<?php

declare(strict_types=1);

namespace Analyses\Framework\Prisma;

use Analyses\Framework\FrameworkInterface;

/**
 * PRISMA — grille de REPORTING des revues systématiques et méta-analyses. Écrit from scratch.
 */
final class PrismaFramework implements FrameworkInterface
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
        ['id' => 'prisma.01', 'dimension' => 'title', 'question' => "Le titre identifie-t-il l'article comme une revue systématique ?"],
        ['id' => 'prisma.02', 'dimension' => 'abstract', 'question' => "Un résumé structuré est-il fourni (objectifs, méthodes, résultats, conclusions) ?"],
        ['id' => 'prisma.03', 'dimension' => 'rationale', 'question' => "La justification de la revue est-elle exposée au regard des connaissances existantes ?"],
        ['id' => 'prisma.04', 'dimension' => 'objectives', 'question' => "Les objectifs/questions sont-ils énoncés explicitement (PICO) ?"],
        ['id' => 'prisma.05', 'dimension' => 'eligibility', 'question' => "Les critères d'inclusion/exclusion sont-ils spécifiés ?"],
        ['id' => 'prisma.06', 'dimension' => 'information_sources', 'question' => "Les sources d'information (bases, dates de dernière recherche) sont-elles décrites ?"],
        ['id' => 'prisma.07', 'dimension' => 'search_strategy', 'question' => "La stratégie de recherche complète (au moins une base) est-elle fournie de façon reproductible ?"],
        ['id' => 'prisma.08', 'dimension' => 'selection_process', 'question' => "Le processus de sélection (screening, nombre d'évaluateurs, indépendance) est-il décrit ?"],
        ['id' => 'prisma.09', 'dimension' => 'data_collection', 'question' => "Le processus d'extraction des données est-il décrit ?"],
        ['id' => 'prisma.10', 'dimension' => 'rob_assessment', 'question' => "La méthode d'évaluation du risque de biais des études incluses est-elle décrite ?"],
        ['id' => 'prisma.11', 'dimension' => 'synthesis_methods', 'question' => "Les méthodes de synthèse (méta-analyse, mesure d'effet, hétérogénéité) sont-elles décrites ?"],
        ['id' => 'prisma.12', 'dimension' => 'study_selection_results', 'question' => "Le flux de sélection des études (diagramme, nombres, exclusions) est-il rapporté ?"],
        ['id' => 'prisma.13', 'dimension' => 'study_characteristics', 'question' => "Les caractéristiques des études incluses sont-elles présentées ?"],
        ['id' => 'prisma.14', 'dimension' => 'rob_results', 'question' => "Les évaluations du risque de biais des études sont-elles rapportées ?"],
        ['id' => 'prisma.15', 'dimension' => 'synthesis_results', 'question' => "Les résultats de synthèse (effets, IC, hétérogénéité I²) sont-ils rapportés ?"],
        ['id' => 'prisma.16', 'dimension' => 'reporting_biases', 'question' => "Les biais de reporting/publication et la certitude des preuves sont-ils évalués ?"],
        ['id' => 'prisma.17', 'dimension' => 'limitations', 'question' => "Les limites des preuves et du processus de revue sont-elles discutées ?"],
        ['id' => 'prisma.18', 'dimension' => 'registration_funding', 'question' => "L'enregistrement (protocole) et le financement sont-ils indiqués ?"],
    ];
}
