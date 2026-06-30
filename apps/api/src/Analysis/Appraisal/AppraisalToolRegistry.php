<?php

declare(strict_types=1);

namespace App\Analysis\Appraisal;

/**
 * Registre des outils d'évaluation critique de la qualité méthodologique, indexés
 * par DEVIS d'étude. Un outil n'est JAMAIS applicable à tous les devis — d'où la
 * pré-détection : on classe le devis, puis on propose les outils adaptés.
 *
 * Seul AXIS est implémenté pour l'instant (les autres grilles viendront) ; le drapeau
 * `implemented` pilote l'UI (bouton actif vs « à venir »). Réf. catevaluation.ca.
 */
final class AppraisalToolRegistry
{
    /** Libellés FR des devis détectés par le classificateur. */
    public const DESIGNS = [
        'cross_sectional' => 'Étude transversale',
        'rct' => 'Essai contrôlé randomisé',
        'non_randomized_trial' => 'Étude d’intervention non randomisée',
        'cohort' => 'Étude de cohorte',
        'case_control' => 'Étude cas-témoins',
        'diagnostic_accuracy' => 'Étude de précision diagnostique',
        'systematic_review' => 'Revue systématique / méta-analyse',
        'qualitative' => 'Étude qualitative',
        'mixed_methods' => 'Étude à méthodes mixtes',
        'case_report' => 'Étude de cas / série de cas',
        'prognostic' => 'Étude pronostique / de prédiction',
        'economic' => 'Évaluation économique',
        'non_empirical' => 'Travail non empirique (théorique, modélisation, éditorial…)',
        'other' => 'Autre / indéterminé',
    ];

    /**
     * Outils : clé → nom + devis couverts + implémenté ?
     *
     * @var array<string,array{name:string,designs:list<string>,implemented:bool}>
     */
    public const TOOLS = [
        'axis' => ['name' => 'AXIS', 'designs' => ['cross_sectional'], 'implemented' => true],
        'rob2' => ['name' => 'Cochrane RoB 2', 'designs' => ['rct'], 'implemented' => false],
        'robins_i' => ['name' => 'ROBINS-I', 'designs' => ['non_randomized_trial'], 'implemented' => false],
        'newcastle_ottawa' => ['name' => 'Newcastle-Ottawa', 'designs' => ['cohort', 'case_control'], 'implemented' => false],
        'quadas2' => ['name' => 'QUADAS-2', 'designs' => ['diagnostic_accuracy'], 'implemented' => false],
        'amstar2' => ['name' => 'AMSTAR-2', 'designs' => ['systematic_review'], 'implemented' => false],
        'robis' => ['name' => 'ROBIS', 'designs' => ['systematic_review'], 'implemented' => false],
        'casp_qualitative' => ['name' => 'CASP Qualitative', 'designs' => ['qualitative'], 'implemented' => false],
        'mmat' => ['name' => 'MMAT', 'designs' => ['mixed_methods', 'rct', 'non_randomized_trial', 'cohort', 'qualitative'], 'implemented' => false],
        'jbi_case' => ['name' => 'JBI (cas / séries)', 'designs' => ['case_report'], 'implemented' => false],
        'quips' => ['name' => 'QUIPS / PROBAST', 'designs' => ['prognostic'], 'implemented' => false],
    ];

    /** @return list<string> clés de devis (pour le prompt du classificateur) */
    public function designKeys(): array
    {
        return array_keys(self::DESIGNS);
    }

    public function designLabel(?string $design): string
    {
        return self::DESIGNS[$design ?? 'other'] ?? self::DESIGNS['other'];
    }

    /** @return list<string> clés d'outils applicables à ce devis */
    public function toolsForDesign(?string $design): array
    {
        if (null === $design) {
            return [];
        }
        $keys = [];
        foreach (self::TOOLS as $key => $meta) {
            if (\in_array($design, $meta['designs'], true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<array{key:string,name:string,implemented:bool}>
     */
    public function toolsMeta(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (isset(self::TOOLS[$key])) {
                $out[] = ['key' => $key, 'name' => self::TOOLS[$key]['name'], 'implemented' => self::TOOLS[$key]['implemented']];
            }
        }

        return $out;
    }
}
