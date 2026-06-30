<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Les 5 domaines de l'outil RoB 2 — *Risk of Bias 2* pour les ESSAIS CONTRÔLÉS
 * RANDOMISÉS (Sterne JAC et al., BMJ 2019;366:l4898. doi:10.1136/bmj.l4898).
 *
 * RoB 2 évalue le RISQUE DE BIAIS par domaine (≠ une note) : chaque domaine est jugé
 * « faible » (low), « quelques réserves » (some_concerns) ou « élevé » (high) à partir
 * de questions de signalement. Le jugement GLOBAL en découle (cf. {@see Rob2Appraiser}).
 */
final class Rob2Checklist
{
    /**
     * Domaine (clé `d1`…`d5`) → { title, focus } (questions de signalement résumées).
     *
     * @var array<string,array{title:string,focus:string}>
     */
    public const DOMAINS = [
        'd1' => [
            'title' => 'Processus de randomisation',
            'focus' => 'La séquence d’allocation était-elle vraiment aléatoire et l’affectation dissimulée (allocation concealment) ? Les groupes étaient-ils comparables à l’inclusion (pas de déséquilibre suggérant un problème de randomisation) ?',
        ],
        'd2' => [
            'title' => 'Écarts aux interventions prévues',
            'focus' => 'Participants et soignants étaient-ils aveugles à l’intervention ? Y a-t-il eu des écarts au protocole liés au contexte d’essai ? L’analyse respectait-elle l’intention de traiter (ITT) ?',
        ],
        'd3' => [
            'title' => 'Données de résultat manquantes',
            'focus' => 'Les données de résultat étaient-elles disponibles pour la quasi-totalité des participants randomisés ? Les abandons/pertes de suivi pouvaient-ils dépendre du résultat (attrition différentielle) ?',
        ],
        'd4' => [
            'title' => 'Mesure du résultat',
            'focus' => 'La méthode de mesure du résultat était-elle appropriée et identique entre groupes ? Les évaluateurs du résultat étaient-ils aveugles au groupe ? La mesure a-t-elle pu être influencée par la connaissance du groupe ?',
        ],
        'd5' => [
            'title' => 'Sélection du résultat rapporté',
            'focus' => 'Existait-il un protocole / plan d’analyse pré-enregistré ? Les résultats rapportés correspondent-ils au plan prévu (absence de sélection des résultats les plus favorables) ?',
        ],
    ];

    /** Jugement de risque par domaine et global. */
    public const JUDGEMENTS = [
        'low' => 'Risque faible',
        'some_concerns' => 'Quelques réserves',
        'high' => 'Risque élevé',
        'insufficient' => 'Information insuffisante',
    ];

    /** Clés ordonnées d1…d5. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DOMAINS);
    }

    public static function judgementLabel(?string $judgement): string
    {
        return self::JUDGEMENTS[$judgement ?? ''] ?? self::JUDGEMENTS['some_concerns'];
    }

    public static function isJudgement(string $j): bool
    {
        return isset(self::JUDGEMENTS[$j]) && 'insufficient' !== $j;
    }
}
