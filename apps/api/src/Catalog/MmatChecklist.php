<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * L'outil MMAT — *Mixed Methods Appraisal Tool*, version 2018 (Hong QN et al.,
 * Education for Information 2018;34:285-291), pour l'évaluation de la qualité
 * méthodologique des études EMPIRIQUES, tous devis confondus : qualitatives,
 * quantitatives (essais randomisés, non randomisés, descriptives) et à méthodes
 * mixtes.
 *
 * Deux questions de FILTRAGE communes ({@see self::SCREENING}) puis CINQ critères
 * propres à la CATÉGORIE détectée ({@see self::CRITERIA}). Chaque critère : oui / non
 * / impossible à déterminer. Les auteurs DÉCONSEILLENT un score global ; on expose un
 * indicateur « n/5 critères remplis » à titre indicatif seulement.
 */
final class MmatChecklist
{
    /**
     * Les cinq catégories d'études couvertes par MMAT → libellé FR.
     *
     * @var array<string,string>
     */
    public const CATEGORIES = [
        'qualitative' => 'Étude qualitative',
        'quant_rct' => 'Essai contrôlé randomisé (quantitatif)',
        'quant_nonrandomized' => 'Étude quantitative non randomisée',
        'quant_descriptive' => 'Étude quantitative descriptive',
        'mixed_methods' => 'Étude à méthodes mixtes',
    ];

    /**
     * Questions de filtrage (S1, S2), communes à toutes les catégories. Un « non » ou
     * « impossible à déterminer » invite à la prudence : l'objet n'est peut-être pas une
     * étude empirique évaluable.
     *
     * @var array<string,string>
     */
    public const SCREENING = [
        's1' => 'Y a-t-il des questions de recherche claires ?',
        's2' => 'Les données recueillies permettent-elles de répondre aux questions de recherche ?',
    ];

    /**
     * Les cinq critères (c1…c5) PAR catégorie. Seule la catégorie détectée est évaluée.
     *
     * @var array<string,array<string,string>>
     */
    public const CRITERIA = [
        'qualitative' => [
            'c1' => 'L’approche qualitative est-elle appropriée pour répondre à la question de recherche ?',
            'c2' => 'Les méthodes de recueil des données qualitatives sont-elles adéquates pour répondre à la question de recherche ?',
            'c3' => 'Les résultats découlent-ils correctement des données ?',
            'c4' => 'L’interprétation des résultats est-elle suffisamment étayée par les données ?',
            'c5' => 'Y a-t-il cohérence entre les sources de données qualitatives, leur recueil, leur analyse et leur interprétation ?',
        ],
        'quant_rct' => [
            'c1' => 'La randomisation est-elle réalisée de façon appropriée ?',
            'c2' => 'Les groupes sont-ils comparables au départ (baseline) ?',
            'c3' => 'Les données de résultats sont-elles complètes ?',
            'c4' => 'Les évaluateurs des résultats sont-ils en aveugle vis-à-vis de l’intervention reçue ?',
            'c5' => 'Les participants ont-ils adhéré à l’intervention qui leur était assignée ?',
        ],
        'quant_nonrandomized' => [
            'c1' => 'Les participants sont-ils représentatifs de la population cible ?',
            'c2' => 'Les mesures sont-elles appropriées, tant pour le résultat que pour l’intervention (ou l’exposition) ?',
            'c3' => 'Les données de résultats sont-elles complètes ?',
            'c4' => 'Les facteurs de confusion sont-ils pris en compte dans le protocole et l’analyse ?',
            'c5' => 'Durant l’étude, l’intervention a-t-elle été administrée (ou l’exposition s’est-elle produite) comme prévu ?',
        ],
        'quant_descriptive' => [
            'c1' => 'La stratégie d’échantillonnage est-elle pertinente pour répondre à la question de recherche ?',
            'c2' => 'L’échantillon est-il représentatif de la population cible ?',
            'c3' => 'Les mesures sont-elles appropriées ?',
            'c4' => 'Le risque de biais de non-réponse est-il faible ?',
            'c5' => 'L’analyse statistique est-elle appropriée pour répondre à la question de recherche ?',
        ],
        'mixed_methods' => [
            'c1' => 'Y a-t-il une justification adéquate du recours à un devis à méthodes mixtes pour répondre à la question de recherche ?',
            'c2' => 'Les différentes composantes de l’étude sont-elles efficacement intégrées pour répondre à la question de recherche ?',
            'c3' => 'Les résultats de l’intégration des composantes qualitative et quantitative sont-ils correctement interprétés ?',
            'c4' => 'Les divergences et incohérences entre résultats quantitatifs et qualitatifs sont-elles correctement traitées ?',
            'c5' => 'Les différentes composantes de l’étude respectent-elles les critères de qualité propres à chaque tradition méthodologique impliquée ?',
        ],
    ];

    /** Réponses possibles (MMAT 2018). */
    public const ANSWERS = ['yes' => 'Oui', 'no' => 'Non', 'cant_tell' => 'Impossible à déterminer'];

    /**
     * Niveaux de qualité INDICATIFS (les auteurs déconseillent un score global ; nous
     * n'exposons qu'un repère à partir du nombre de critères remplis sur 5).
     */
    public const RATINGS = [
        'high' => 'Qualité élevée (5/5 critères remplis)',
        'moderate' => 'Qualité modérée',
        'low' => 'Qualité faible',
        'insufficient' => 'Indéterminée (texte intégral requis)',
    ];

    /** Clés ordonnées des cinq critères : c1…c5. @return list<string> */
    public static function criterionKeys(): array
    {
        return ['c1', 'c2', 'c3', 'c4', 'c5'];
    }

    /** Clés ordonnées des questions de filtrage : s1, s2. @return list<string> */
    public static function screeningKeys(): array
    {
        return array_keys(self::SCREENING);
    }

    public static function isCategory(string $category): bool
    {
        return isset(self::CATEGORIES[$category]);
    }

    public static function categoryLabel(?string $category): string
    {
        return self::CATEGORIES[$category ?? ''] ?? 'Catégorie indéterminée';
    }

    /**
     * Les cinq critères de la catégorie donnée (vide si catégorie inconnue).
     *
     * @return array<string,string>
     */
    public static function criteriaFor(?string $category): array
    {
        return self::CRITERIA[$category ?? ''] ?? [];
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
     * Repère de qualité INDICATIF à partir du nombre de critères remplis (« oui ») sur
     * les cinq. Volontairement grossier : MMAT proscrit un score, ce n'est qu'un signal.
     */
    public static function overall(int $metCount): string
    {
        if ($metCount >= 5) {
            return 'high';
        }
        if ($metCount >= 3) {
            return 'moderate';
        }

        return 'low';
    }
}
