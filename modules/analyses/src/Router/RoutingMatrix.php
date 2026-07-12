<?php

declare(strict_types=1);

namespace Analyses\Router;

use Analyses\Ontology\StudyDesign;

/**
 * Matrice principale de routage par type d'étude (cf. docs/Modules/analyses/SPECS.md §8).
 * Socle par défaut, extensible et versionné. Chaque entrée décrit le référentiel principal,
 * le reporting, l'outil de risque de biais et les modules complémentaires.
 */
final class RoutingMatrix
{
    public const VERSION = '2026.1';

    /**
     * @return array{primary: list<string>, reporting: list<string>, risk_of_bias: list<string>, modules: list<string>}
     */
    public function forDesign(StudyDesign $design): array
    {
        return self::TABLE[$design->value] ?? self::TABLE['unknown'];
    }

    /** @var array<string, array{primary: list<string>, reporting: list<string>, risk_of_bias: list<string>, modules: list<string>}> */
    private const TABLE = [
        'randomized_controlled_trial' => [
            'primary' => ['rct_appraisal'], 'reporting' => ['consort'], 'risk_of_bias' => ['rob2'],
            'modules' => ['effect_size', 'power', 'intention_to_treat', 'missing_data', 'randomization', 'allocation_concealment', 'blinding', 'safety'],
        ],
        'cluster_randomized_trial' => [
            'primary' => ['rct_cluster_appraisal'], 'reporting' => ['consort_cluster'], 'risk_of_bias' => ['rob2_cluster'],
            'modules' => ['cluster_effect', 'icc', 'adjusted_power', 'contamination'],
        ],
        'crossover_trial' => [
            'primary' => ['crossover_appraisal'], 'reporting' => ['consort_crossover'], 'risk_of_bias' => ['rob2_crossover'],
            'modules' => ['period_effect', 'carryover', 'washout', 'sequence_order'],
        ],
        'non_randomized_intervention' => [
            'primary' => ['nonrandomized_appraisal'], 'reporting' => ['trend'], 'risk_of_bias' => ['robins_i'],
            'modules' => ['confounding_analysis', 'comparability', 'selection_bias'],
        ],
        'cohort_prospective' => [
            'primary' => ['cohort_appraisal'], 'reporting' => ['strobe_cohort'], 'risk_of_bias' => ['robins_i'],
            'modules' => ['survival', 'attrition', 'confounding_analysis', 'temporality', 'loss_to_followup'],
        ],
        'cohort_retrospective' => [
            'primary' => ['cohort_appraisal'], 'reporting' => ['strobe_cohort'], 'risk_of_bias' => ['robins_i'],
            'modules' => ['missing_data', 'confounding_analysis', 'secondary_data_quality'],
        ],
        'case_control' => [
            'primary' => ['case_control_appraisal'], 'reporting' => ['strobe_case_control'], 'risk_of_bias' => ['robins_i'],
            'modules' => ['odds_ratio', 'matching', 'recall_bias', 'control_selection'],
        ],
        'cross_sectional' => [
            'primary' => ['axis'], 'reporting' => ['strobe_cross_sectional'], 'risk_of_bias' => ['observational_bias_core'],
            'modules' => ['prevalence', 'sampling_bias', 'weighting', 'confounding_analysis', 'no_causal_inference', 'representativeness'],
        ],
        'ecological' => [
            'primary' => ['ecological_appraisal'], 'reporting' => ['strobe_adapted'], 'risk_of_bias' => ['ecological_bias_core'],
            'modules' => ['aggregate_correlation', 'ecological_fallacy'],
        ],
        'diagnostic_accuracy' => [
            'primary' => ['diagnostic_appraisal'], 'reporting' => ['stard'], 'risk_of_bias' => ['quadas_2'],
            'modules' => ['diagnostic_statistics', 'thresholds', 'reference_standard'],
        ],
        'prognostic_factor' => [
            'primary' => ['prognostic_appraisal'], 'reporting' => ['remark'], 'risk_of_bias' => ['quips'],
            'modules' => ['calibration', 'discrimination', 'followup', 'competing_factors'],
        ],
        'prediction_model_development' => [
            'primary' => ['prediction_model_appraisal'], 'reporting' => ['tripod'], 'risk_of_bias' => ['probast'],
            'modules' => ['internal_validation', 'overfitting', 'variable_selection'],
        ],
        'prediction_model_validation' => [
            'primary' => ['external_validation_appraisal'], 'reporting' => ['tripod'], 'risk_of_bias' => ['probast'],
            'modules' => ['external_calibration', 'transportability', 'dataset_shift'],
        ],
        'qualitative' => [
            'primary' => ['casp_qualitative'], 'reporting' => ['coreq', 'srqr'], 'risk_of_bias' => ['qualitative_rigor'],
            'modules' => ['saturation', 'reflexivity', 'triangulation'],
        ],
        'mixed_methods' => [
            'primary' => ['mmat'], 'reporting' => ['mixed_reporting'], 'risk_of_bias' => ['mmat'],
            'modules' => ['integration_coherence'],
        ],
        'case_report' => [
            'primary' => ['case_report_appraisal'], 'reporting' => ['care'], 'risk_of_bias' => [],
            'modules' => ['descriptive', 'limited_causality'],
        ],
        'case_series' => [
            'primary' => ['case_series_appraisal'], 'reporting' => ['process_adapted'], 'risk_of_bias' => ['case_series_bias'],
            'modules' => ['descriptive', 'selection_exhaustiveness'],
        ],
        'animal_study' => [
            'primary' => ['animal_appraisal'], 'reporting' => ['arrive'], 'risk_of_bias' => ['syrcle_rob'],
            'modules' => ['randomization', 'blinding', 'sample_size', 'animal_welfare', 'translational_validity'],
        ],
        'in_vitro' => [
            'primary' => ['in_vitro_appraisal'], 'reporting' => ['domain_guideline'], 'risk_of_bias' => ['internal_bias'],
            'modules' => ['replications', 'normalization', 'contamination', 'cell_line_authentication'],
        ],
        'laboratory_experiment' => [
            'primary' => ['experimental_appraisal'], 'reporting' => ['domain_guideline'], 'risk_of_bias' => ['internal_bias'],
            'modules' => ['replication', 'uncertainty', 'calibration', 'experimental_control'],
        ],
        'simulation_study' => [
            'primary' => ['simulation_appraisal'], 'reporting' => ['simulation_reporting'], 'risk_of_bias' => ['internal_bias'],
            'modules' => ['sensitivity', 'stability', 'assumptions', 'model_validation'],
        ],
        'computational_experiment' => [
            'primary' => ['computational_appraisal'], 'reporting' => ['cs_guideline'], 'risk_of_bias' => ['internal_bias'],
            'modules' => ['variance', 'repetitions', 'hardware', 'dependencies', 'seed'],
        ],
        'algorithm_benchmark' => [
            'primary' => ['ai_benchmark_appraisal'], 'reporting' => ['ml_guideline'], 'risk_of_bias' => ['internal_bias'],
            'modules' => ['benchmark_selection', 'train_test_leakage', 'multiple_comparisons', 'variance', 'contamination_risk'],
        ],
        'economic_evaluation' => [
            'primary' => ['economic_appraisal'], 'reporting' => ['cheers'], 'risk_of_bias' => ['economic_bias'],
            'modules' => ['icer', 'sensitivity', 'perspective', 'time_horizon'],
        ],
        'systematic_review' => [
            'primary' => ['amstar2'], 'reporting' => ['prisma'], 'risk_of_bias' => ['robis'],
            'modules' => ['synthesis', 'heterogeneity', 'search_strategy', 'selection', 'publication_bias'],
        ],
        'meta_analysis' => [
            'primary' => ['amstar2'], 'reporting' => ['prisma'], 'risk_of_bias' => ['robis'],
            'modules' => ['fixed_random_effects', 'i_squared', 'publication_bias'],
        ],
        'umbrella_review' => [
            'primary' => ['umbrella_appraisal'], 'reporting' => ['prior'], 'risk_of_bias' => ['robis_adapted'],
            'modules' => ['overlap', 'heterogeneity', 'study_duplication'],
        ],
        'scoping_review' => [
            'primary' => ['jbi_scoping'], 'reporting' => ['prisma_scr'], 'risk_of_bias' => [],
            'modules' => ['descriptive', 'field_coverage'],
        ],
        'bibliometric_study' => [
            'primary' => ['bibliometric_appraisal'], 'reporting' => ['bibliometric_reporting'], 'risk_of_bias' => ['internal_bias'],
            'modules' => ['network', 'normalization', 'database_bias'],
        ],
        'replication_study' => [
            'primary' => ['replication_appraisal'], 'reporting' => ['original_plus_replication'], 'risk_of_bias' => ['internal_bias'],
            'modules' => ['equivalence', 'precision', 'protocol_fidelity'],
        ],
        'methods_validation' => [
            'primary' => ['methods_validation_appraisal'], 'reporting' => ['domain_guideline'], 'risk_of_bias' => ['internal_bias'],
            'modules' => ['repeatability', 'reproducibility', 'calibration', 'robustness'],
        ],
        'unknown' => [
            'primary' => [], 'reporting' => [], 'risk_of_bias' => [],
            'modules' => ['integrity_core', 'reproducibility_core', 'claim_consistency_core'],
        ],
    ];
}
