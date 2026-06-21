<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Familles de types de publication (vocabulaire OpenAlex, + variantes CSL/Zotero
 * rencontrées dans les données). Sert au regroupement des statistiques et à
 * l'exclusion des « satellites » (objets rattachés à un article : rapport de
 * relecture, erratum, rétractation, annexes…) de la recherche et du RAG.
 */
final class PublicationType
{
    /**
     * Types « satellites » : pas du contenu de recherche autonome, mais des
     * objets gravitant autour d'un article parent (ou des pages techniques).
     * Exclus de la recherche sémantique, du plein-texte et des sources du RAG.
     *
     * @var list<string>
     */
    public const SATELLITE = [
        'peer-review',
        'erratum',
        'retraction',
        'supplementary-materials',
        'paratext',
        'grant',
        'libguides',
        'webpage',
    ];

    /**
     * Sous-ensemble des satellites véritablement RATTACHÉS à un article parent
     * (donc concernés par la résolution du lien parent / le rattrapage).
     *
     * @var list<string>
     */
    public const ATTACHED = [
        'peer-review',
        'erratum',
        'retraction',
        'supplementary-materials',
        'paratext',
    ];

    /**
     * Regroupement par famille pour l'affichage des statistiques (libellé => types).
     *
     * @var array<string, list<string>>
     */
    public const FAMILIES = [
        'Recherche (papiers primaires)' => ['article', 'journalArticle', 'preprint', 'conferencePaper', 'conference-paper'],
        'Production savante' => ['review', 'book', 'book-chapter', 'bookSection', 'book-section', 'report', 'report-component', 'dissertation', 'dataset', 'standard'],
        'Éditorial & référence' => ['editorial', 'letter', 'reference-entry', 'other'],
        'Satellites (rattachés / exclus de la recherche)' => self::SATELLITE,
    ];

    /** Liste SQL entre quotes (sûre : valeurs en dur) pour un IN/NOT IN. */
    public static function sqlList(array $types): string
    {
        return implode(',', array_map(static fn (string $t): string => "'".$t."'", $types));
    }

    /** Clause « type NOT IN (satellites) » à coller après un AND/WHERE. */
    public static function notSatelliteSql(string $col = 'type'): string
    {
        return $col.' NOT IN ('.self::sqlList(self::SATELLITE).')';
    }
}
