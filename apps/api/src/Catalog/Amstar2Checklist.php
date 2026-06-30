<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Les 16 items d'AMSTAR-2 — *A MeaSurement Tool to Assess systematic Reviews 2*
 * (Shea BJ et al., BMJ 2017;358:j4008. doi:10.1136/bmj.j4008), pour les REVUES
 * SYSTÉMATIQUES (avec ou sans méta-analyse).
 *
 * AMSTAR-2 ne donne PAS de score : un niveau de CONFIANCE global (élevée/modérée/
 * faible/très faible) découle du nombre de défauts sur les 7 domaines CRITIQUES
 * ({@see self::CRITICAL}) et de réserves sur les items non critiques.
 */
final class Amstar2Checklist
{
    /**
     * Item (clé `q1`…`q16`) → texte FR. Ordre = ordre officiel AMSTAR-2.
     *
     * @var array<string,string>
     */
    public const ITEMS = [
        'q1' => 'Les questions et critères d’inclusion incluaient-ils les composantes PICO (population, intervention, comparateur, résultat) ?',
        'q2' => 'Le protocole était-il établi AVANT la revue (pré-enregistrement), et les écarts éventuels justifiés ?',
        'q3' => 'Le choix des types d’études à inclure était-il justifié ?',
        'q4' => 'Une stratégie de recherche documentaire exhaustive a-t-elle été employée (plusieurs bases, etc.) ?',
        'q5' => 'La sélection des études a-t-elle été réalisée en double (deux relecteurs indépendants) ?',
        'q6' => 'L’extraction des données a-t-elle été réalisée en double ?',
        'q7' => 'Une liste des études EXCLUES, avec justification, a-t-elle été fournie ?',
        'q8' => 'Les études incluses sont-elles décrites de façon suffisamment détaillée ?',
        'q9' => 'Une technique satisfaisante d’évaluation du risque de biais des études incluses a-t-elle été utilisée ?',
        'q10' => 'Les sources de financement des études incluses ont-elles été rapportées ?',
        'q11' => 'Le cas échéant, les méthodes de combinaison statistique (méta-analyse) étaient-elles appropriées ?',
        'q12' => 'Le cas échéant, l’impact du risque de biais sur les résultats de la méta-analyse a-t-il été évalué ?',
        'q13' => 'Le risque de biais des études individuelles a-t-il été pris en compte dans l’interprétation des résultats ?',
        'q14' => 'Une explication / discussion de l’hétérogénéité observée a-t-elle été fournie ?',
        'q15' => 'Le cas échéant, le biais de publication a-t-il été investigué et son impact discuté ?',
        'q16' => 'Les conflits d’intérêts, y compris les financements de la revue, ont-ils été déclarés ?',
    ];

    /**
     * Les 7 domaines CRITIQUES (un « non » = défaut critique abaissant fortement la
     * confiance) : protocole, recherche exhaustive, études exclues justifiées,
     * risque de biais évalué, méta-analyse appropriée, RoB pris en compte, biais de
     * publication.
     *
     * @var list<string>
     */
    public const CRITICAL = ['q2', 'q4', 'q7', 'q9', 'q11', 'q13', 'q15'];

    /** Réponses possibles. */
    public const ANSWERS = ['yes' => 'Oui', 'partial_yes' => 'Oui partiel', 'no' => 'Non'];

    /** Niveaux de confiance globaux. */
    public const RATINGS = [
        'high' => 'Confiance élevée',
        'moderate' => 'Confiance modérée',
        'low' => 'Confiance faible',
        'critically_low' => 'Confiance très faible',
        'insufficient' => 'Indéterminée (texte intégral requis)',
    ];

    /** Clés ordonnées q1…q16. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ITEMS);
    }

    public static function isCritical(string $key): bool
    {
        return \in_array($key, self::CRITICAL, true);
    }

    public static function isAnswer(string $a): bool
    {
        return isset(self::ANSWERS[$a]);
    }

    public static function answerLabel(?string $a): string
    {
        return self::ANSWERS[$a ?? ''] ?? '—';
    }

    public static function ratingLabel(?string $r): string
    {
        return self::RATINGS[$r ?? ''] ?? '—';
    }

    /**
     * Niveau de confiance global (algorithme AMSTAR-2) à partir du nombre de défauts
     * CRITIQUES (« non » sur un item critique) et de réserves non critiques.
     */
    public static function overall(int $criticalFlaws, int $weaknesses): string
    {
        if ($criticalFlaws > 1) {
            return 'critically_low';
        }
        if (1 === $criticalFlaws) {
            return 'low';
        }
        if ($weaknesses > 1) {
            return 'moderate';
        }

        return 'high';
    }
}
