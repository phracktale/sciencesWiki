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
     * Papiers de recherche PRIMAIRES (article + preprint + variantes). Seuls ces
     * types remontent par défaut dans la recherche publique (front).
     *
     * @var list<string>
     */
    public const PRIMARY = ['article', 'journalArticle', 'preprint', 'conferencePaper', 'conference-paper'];

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

    /**
     * Familles proposables à l'utilisateur dans un filtre (sans les satellites).
     *
     * @return array<string, list<string>>
     */
    public static function selectableFamilies(): array
    {
        $families = self::FAMILIES;
        unset($families['Satellites (rattachés / exclus de la recherche)']);

        return $families;
    }

    /**
     * Normalise une liste de types demandée pour la recherche : retire les
     * satellites ; si vide, retombe sur les papiers primaires.
     *
     * @param list<string> $types
     *
     * @return list<string>
     */
    public static function searchTypes(array $types): array
    {
        $clean = array_values(array_diff($types, self::SATELLITE));

        return [] !== $clean ? $clean : self::PRIMARY;
    }

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
