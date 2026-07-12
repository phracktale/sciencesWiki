<?php

declare(strict_types=1);

namespace Analyses\Router;

use Analyses\Ontology\StudyDesign;

/**
 * Assemble un plan d'analyse composite (cf. docs/Modules/analyses/SPECS.md §12) :
 * socle par type d'étude + surcouches finalité/domaine/modalité + noyaux transverses.
 * Déterministe et testable sans modèle génératif (SPECS §28).
 */
final class RouterEngine
{
    public function __construct(private readonly RoutingMatrix $matrix = new RoutingMatrix())
    {
    }

    /**
     * @param list<string> $objectives
     * @param list<string> $domains
     * @param list<string> $modalities
     *
     * @return array<string, mixed>
     */
    public function buildPlan(StudyDesign $design, array $objectives = [], array $domains = [], array $modalities = []): array
    {
        $base = $this->matrix->forDesign($design);

        $modules = $base['modules'];
        foreach ($objectives as $o) {
            $modules = [...$modules, ...(self::OBJECTIVE_OVERLAYS[$o] ?? [])];
        }
        foreach ($domains as $d) {
            $modules = [...$modules, ...(self::DOMAIN_OVERLAYS[$d] ?? [])];
        }
        foreach ($modalities as $m) {
            $modules = [...$modules, ...(self::MODALITY_OVERLAYS[$m] ?? [])];
        }
        // Noyaux transverses toujours présents (SPECS §12.2).
        $modules = [...$modules, 'integrity_core', 'reproducibility_core', 'claim_consistency_core'];

        return [
            'route_version' => RoutingMatrix::VERSION,
            'primary_design' => $design->value,
            'primary_frameworks' => $base['primary'],
            'reporting_frameworks' => $base['reporting'],
            'risk_of_bias_tools' => $base['risk_of_bias'],
            'analysis_modules' => array_values(array_unique($modules)),
        ];
    }

    /** @var array<string, list<string>> Surcouches par finalité (SPECS §11). */
    private const OBJECTIVE_OVERLAYS = [
        'efficacy' => ['causal_effect', 'comparator', 'adherence', 'intention_to_treat'],
        'safety' => ['adverse_events', 'followup_duration', 'safety_power'],
        'causality' => ['temporality', 'confounding_analysis', 'selection_bias', 'assumptions'],
        'association' => ['adjustment', 'multiplicity', 'no_causal_inference'],
        'prevalence' => ['representativeness', 'weighting', 'case_definition'],
        'incidence' => ['population_at_risk', 'followup', 'loss_to_followup'],
        'diagnosis' => ['reference_standard', 'blinding', 'thresholds'],
        'prognosis' => ['followup', 'calibration', 'competing_events'],
        'prediction' => ['validation', 'overfitting', 'performance', 'clinical_utility'],
        'method_validation' => ['repeatability', 'reproducibility', 'accuracy'],
        'comparison' => ['condition_equivalence', 'multiplicity'],
        'exploration' => ['exploratory_nature', 'post_hoc_hypotheses'],
        'replication' => ['protocol_fidelity', 'power', 'compatibility'],
        'qualitative_understanding' => ['saturation', 'reflexivity', 'triangulation'],
        'economic_estimation' => ['perspective', 'time_horizon', 'discounting', 'sensitivity'],
    ];

    /** @var array<string, list<string>> Surcouches par domaine (SPECS §9, extrait). */
    private const DOMAIN_OVERLAYS = [
        'medicine' => ['clinical_applicability', 'safety', 'patient_relevance'],
        'epidemiology' => ['confounding_analysis', 'selection_bias', 'temporality'],
        'public_health' => ['generalization', 'equity', 'context'],
        'psychology' => ['psychometric_validity', 'common_method_bias', 'self_report_bias'],
        'neuroscience' => ['multiplicity_correction', 'signal_pipeline', 'small_sample'],
        'pharmacology' => ['dose_response', 'pharmacokinetics', 'safety'],
        'genetics' => ['sequencing_qc', 'multiple_testing', 'population_stratification'],
        'biology' => ['cell_line_authentication', 'replications', 'image_integrity'],
        'microbiology' => ['contamination', 'culture_conditions'],
        'ecology' => ['spatial_structure', 'pseudoreplication', 'autocorrelation'],
        'climatology' => ['time_series', 'model_scenarios', 'uncertainty'],
        'economics' => ['causal_identification', 'robustness', 'specification_choice'],
        'sociology' => ['sampling', 'context', 'declarative_bias'],
        'education' => ['clustering', 'teacher_effect', 'attrition'],
        'physics' => ['instrumental_uncertainty', 'calibration'],
        'chemistry' => ['purity', 'repeatability', 'characterization'],
        'materials_science' => ['sample_preparation', 'characterization'],
        'computer_science' => ['software_reproducibility'],
        'artificial_intelligence' => ['data_leakage', 'dataset_shift', 'external_validation', 'seeds', 'metrics'],
        'robotics' => ['real_environment', 'robustness'],
        'mathematics' => ['formal_validity'],
        'engineering' => ['tolerance', 'safety', 'constraints'],
    ];

    /** @var array<string, list<string>> Surcouches par modalité de données (SPECS §10, extrait). */
    private const MODALITY_OVERLAYS = [
        'tabular' => ['data_quality', 'missing_values'],
        'survey' => ['psychometric_validity', 'declarative_bias'],
        'questionnaire' => ['psychometric_validity', 'declarative_bias'],
        'interview' => ['qualitative_coding', 'saturation'],
        'scientific_image' => ['image_integrity'],
        'medical_imaging' => ['reading_reliability', 'segmentation', 'standardization'],
        'microscopy' => ['acquisition_quantification', 'normalization'],
        'western_blot' => ['band_integrity', 'controls'],
        'genomic_sequence' => ['bioinformatic_pipeline', 'multiple_testing'],
        'omics' => ['bioinformatic_pipeline', 'multiple_testing'],
        'time_series' => ['temporal_dependence', 'stationarity'],
        'physiological_signal' => ['filtering_artifacts', 'preprocessing'],
        'geospatial' => ['spatial_dependence', 'maup', 'autocorrelation'],
        'text_corpus' => ['annotation', 'representativeness'],
        'source_code' => ['software_reproducibility', 'dependencies', 'license'],
        'simulation_output' => ['numerical_robustness', 'seeds', 'convergence'],
        'sensor_data' => ['calibration', 'drift', 'synchronization'],
        'synthetic_data' => ['fidelity', 'privacy', 'leakage'],
        'electronic_health_record' => ['coding', 'capture_bias'],
        'administrative_database' => ['secondary_quality', 'original_purpose'],
    ];
}
