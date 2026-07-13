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
    /**
     * Chemin arborescent (racine → rubrique) de la publication dans l'arbre des savoirs.
     * Prend le placement de meilleur score puis remonte les parents « principaux ».
     *
     * @return list<array{slug: string, label: string}>
     */
    public function treePath(int $publicationId): array
    {
        $nodeId = $this->db->fetchOne(
            'SELECT tree_node_id FROM placement_suggestion WHERE publication_id = :id ORDER BY score DESC NULLS LAST LIMIT 1',
            ['id' => $publicationId],
        );
        if (false === $nodeId || null === $nodeId) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'WITH RECURSIVE up AS (
                SELECT tn.id, tn.slug, tn.label, 0 AS depth
                FROM tree_node tn WHERE tn.id = :node
                UNION ALL
                SELECT p.id, p.slug, p.label, u.depth + 1
                FROM up u
                JOIN LATERAL (
                    SELECT parent_id FROM tree_edge WHERE child_id = u.id ORDER BY principal DESC LIMIT 1
                ) e ON true
                JOIN tree_node p ON p.id = e.parent_id
             )
             SELECT slug, label FROM up ORDER BY depth DESC',
            ['node' => (int) $nodeId],
        );

        return array_map(static fn (array $r): array => ['slug' => (string) $r['slug'], 'label' => (string) $r['label']], $rows);
    }

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
