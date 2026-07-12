<?php

declare(strict_types=1);

namespace Analyses\Framework\Axis;

use Analyses\Framework\FrameworkInterface;

/**
 * Référentiel AXIS (Appraisal tool for cross-sectional studies) — écrit from scratch
 * pour le module (SPECS §7.4). Ne reprend RIEN du code d'analyse legacy de SciencesWiki.
 *
 * Ce plugin déclare ses métadonnées et la liste des critères AXIS ; l'exécution
 * (interrogation LLM ancrée sur les sources) sera portée par l'analyseur associé.
 */
final class AxisFramework implements FrameworkInterface
{
    public function id(): string
    {
        return 'axis';
    }

    public function metadata(): array
    {
        return [
            'name' => 'AXIS',
            'version' => '1.0',
            'framework_type' => 'critical_appraisal',
            'supported_designs' => ['cross_sectional'],
            'supported_domains' => ['*'],
            'required_inputs' => ['full_text'],
            'dimensions' => ['methodological_quality', 'reporting_quality', 'risk_of_bias'],
            'incompatibilities' => ['randomized_controlled_trial'],
            'criteria_count' => \count(self::CRITERIA),
        ];
    }

    /**
     * Les 20 items AXIS (dimension + intitulé). Base de l'analyseur AXIS.
     *
     * @return list<array{id: string, dimension: string, question: string}>
     */
    public function criteria(): array
    {
        return self::CRITERIA;
    }

    /** @var list<array{id: string, dimension: string, question: string}> */
    private const CRITERIA = [
        ['id' => 'axis.q01', 'dimension' => 'aims', 'question' => "Les objectifs/hypothèses de l'étude sont-ils clairs ?"],
        ['id' => 'axis.q02', 'dimension' => 'design', 'question' => "Le plan d'étude est-il adapté aux objectifs déclarés ?"],
        ['id' => 'axis.q03', 'dimension' => 'sample_size', 'question' => "La taille de l'échantillon est-elle justifiée ?"],
        ['id' => 'axis.q04', 'dimension' => 'target_population', 'question' => "La population cible est-elle clairement définie ?"],
        ['id' => 'axis.q05', 'dimension' => 'sampling_frame', 'question' => "La base de sondage était-elle représentative de la population cible ?"],
        ['id' => 'axis.q06', 'dimension' => 'sample_selection', 'question' => "Le processus de sélection était-il susceptible de sélectionner des sujets représentatifs ?"],
        ['id' => 'axis.q07', 'dimension' => 'non_responders', 'question' => "Des mesures ont-elles été prises face aux non-répondants ?"],
        ['id' => 'axis.q08', 'dimension' => 'measurement_validity', 'question' => "Les variables de résultat/exposition mesurées sont-elles valides et fiables ?"],
        ['id' => 'axis.q09', 'dimension' => 'measurement_reliability', 'question' => "Les instruments de mesure ont-ils été décrits (pilotés/publiés) ?"],
        ['id' => 'axis.q10', 'dimension' => 'statistical_significance', 'question' => "La signification statistique est-elle déterminée de façon appropriée ?"],
        ['id' => 'axis.q11', 'dimension' => 'methods_clarity', 'question' => "Les méthodes sont-elles suffisamment décrites pour être reproduites ?"],
        ['id' => 'axis.q12', 'dimension' => 'basic_data', 'question' => "Les données de base sont-elles correctement décrites ?"],
        ['id' => 'axis.q13', 'dimension' => 'response_rate', 'question' => "Le taux de réponse soulève-t-il des inquiétudes de biais de non-réponse ?"],
        ['id' => 'axis.q14', 'dimension' => 'non_responders_info', 'question' => "L'information sur les non-répondants est-elle décrite ?"],
        ['id' => 'axis.q15', 'dimension' => 'results_consistency', 'question' => "Les résultats sont-ils cohérents en interne ?"],
        ['id' => 'axis.q16', 'dimension' => 'results_for_analyses', 'question' => "Les résultats sont-ils présentés pour toutes les analyses décrites dans les méthodes ?"],
        ['id' => 'axis.q17', 'dimension' => 'discussion_justified', 'question' => "Les discussions/conclusions sont-elles justifiées par les résultats ?"],
        ['id' => 'axis.q18', 'dimension' => 'limitations', 'question' => "Les limites de l'étude sont-elles discutées ?"],
        ['id' => 'axis.q19', 'dimension' => 'funding_conflicts', 'question' => "Financement/conflits d'intérêts susceptibles d'affecter l'interprétation ?"],
        ['id' => 'axis.q20', 'dimension' => 'ethics', 'question' => "L'approbation éthique / le consentement ont-ils été obtenus ?"],
    ];
}
