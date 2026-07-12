<?php

declare(strict_types=1);

namespace Analyses\Sdk;

use Doctrine\DBAL\Connection;

/**
 * Port SDK « publications:read » (SPECS framework §9) : lecture SEULE du corpus
 * SciencesWiki dans la base partagée. Aucune écriture, aucune migration du cœur.
 * Le plein texte est lu depuis les chunks vectorisés (publication_chunk).
 */
final class CorpusPort
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Métadonnées d'une publication par identifiant numérique ou DOI.
     *
     * @return array<string, mixed>|null
     */
    public function findPublication(string $ref): ?array
    {
        $ref = trim($ref);
        if ('' === $ref) {
            return null;
        }

        $sql = 'SELECT id, doi, title, abstract, oa_status, type, publication_date, fulltext_stored
                FROM publication WHERE %s LIMIT 1';

        $row = ctype_digit($ref)
            ? $this->db->fetchAssociative(\sprintf($sql, 'id = :v'), ['v' => (int) $ref])
            : $this->db->fetchAssociative(\sprintf($sql, 'doi = :v'), ['v' => $ref]);

        return $row ?: null;
    }

    /**
     * Plein texte reconstitué (concaténation ordonnée des chunks embeddés).
     *
     * @return string le texte disponible (peut être vide si non vectorisé)
     */
    public function fulltext(int $publicationId, int $maxChunks = 400): string
    {
        $maxChunks = max(1, min(2000, $maxChunks));
        $chunks = $this->db->fetchFirstColumn(
            \sprintf(
                'SELECT content FROM publication_chunk WHERE publication_id = :id ORDER BY ord ASC LIMIT %d',
                $maxChunks,
            ),
            ['id' => $publicationId],
        );

        return implode("\n\n", array_map('strval', $chunks));
    }
}
