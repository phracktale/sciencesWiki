<?php

declare(strict_types=1);

namespace Analyses\Ontology;

/**
 * Plans d'étude reconnus (cf. docs/Modules/analyses/SPECS.md §6.2). Le code sélectionne
 * le socle méthodologique via {@see \Analyses\Router\RoutingMatrix}.
 */
enum StudyDesign: string
{
    case RandomizedControlledTrial = 'randomized_controlled_trial';
    case ClusterRandomizedTrial = 'cluster_randomized_trial';
    case CrossoverTrial = 'crossover_trial';
    case NonRandomizedIntervention = 'non_randomized_intervention';
    case CohortProspective = 'cohort_prospective';
    case CohortRetrospective = 'cohort_retrospective';
    case CaseControl = 'case_control';
    case CrossSectional = 'cross_sectional';
    case Ecological = 'ecological';
    case DiagnosticAccuracy = 'diagnostic_accuracy';
    case PrognosticFactor = 'prognostic_factor';
    case PredictionModelDevelopment = 'prediction_model_development';
    case PredictionModelValidation = 'prediction_model_validation';
    case Qualitative = 'qualitative';
    case MixedMethods = 'mixed_methods';
    case CaseReport = 'case_report';
    case CaseSeries = 'case_series';
    case AnimalStudy = 'animal_study';
    case InVitro = 'in_vitro';
    case LaboratoryExperiment = 'laboratory_experiment';
    case SimulationStudy = 'simulation_study';
    case ComputationalExperiment = 'computational_experiment';
    case AlgorithmBenchmark = 'algorithm_benchmark';
    case EconomicEvaluation = 'economic_evaluation';
    case SystematicReview = 'systematic_review';
    case MetaAnalysis = 'meta_analysis';
    case UmbrellaReview = 'umbrella_review';
    case ScopingReview = 'scoping_review';
    case BibliometricStudy = 'bibliometric_study';
    case ReplicationStudy = 'replication_study';
    case MethodsValidation = 'methods_validation';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::RandomizedControlledTrial => 'Essai randomisé contrôlé',
            self::ClusterRandomizedTrial => 'Essai randomisé en grappes',
            self::CrossoverTrial => 'Essai croisé',
            self::NonRandomizedIntervention => 'Intervention non randomisée',
            self::CohortProspective => 'Cohorte prospective',
            self::CohortRetrospective => 'Cohorte rétrospective',
            self::CaseControl => 'Cas-témoins',
            self::CrossSectional => 'Étude transversale',
            self::Ecological => 'Étude écologique',
            self::DiagnosticAccuracy => 'Étude diagnostique',
            self::PrognosticFactor => 'Étude pronostique',
            self::PredictionModelDevelopment => 'Développement de modèle prédictif',
            self::PredictionModelValidation => 'Validation de modèle prédictif',
            self::Qualitative => 'Étude qualitative',
            self::MixedMethods => 'Méthodes mixtes',
            self::CaseReport => 'Cas clinique',
            self::CaseSeries => 'Série de cas',
            self::AnimalStudy => 'Étude animale',
            self::InVitro => 'Étude in vitro',
            self::LaboratoryExperiment => 'Expérience de laboratoire',
            self::SimulationStudy => 'Étude de simulation',
            self::ComputationalExperiment => 'Expérience computationnelle',
            self::AlgorithmBenchmark => 'Benchmark algorithmique',
            self::EconomicEvaluation => 'Évaluation économique',
            self::SystematicReview => 'Revue systématique',
            self::MetaAnalysis => 'Méta-analyse',
            self::UmbrellaReview => 'Umbrella review',
            self::ScopingReview => 'Scoping review',
            self::BibliometricStudy => 'Étude bibliométrique',
            self::ReplicationStudy => 'Étude de réplication',
            self::MethodsValidation => 'Validation de méthode',
            self::Unknown => 'Indéterminé',
        };
    }
}
