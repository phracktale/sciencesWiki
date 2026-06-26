<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Accès aux empreintes MinHash/LSH des chunks (table brute chunk_fingerprint +
 * chunk_fingerprint_band, cf. docs/spec-plagiat.md §4.1). Service DBAL (pas une
 * entité Doctrine : publication_chunk est lui-même une table brute).
 */
final class ChunkFingerprintRepository
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function hasFingerprints(int $publicationId): bool
    {
        return (bool) $this->conn->fetchOne(
            'SELECT 1 FROM chunk_fingerprint WHERE publication_id = :p LIMIT 1',
            ['p' => $publicationId],
        );
    }

    public function deleteForPublication(int $publicationId): void
    {
        // Les bandes tombent par CASCADE (FK).
        $this->conn->executeStatement('DELETE FROM chunk_fingerprint WHERE publication_id = :p', ['p' => $publicationId]);
    }

    /**
     * Fragments plein texte d'une publication (à empreinter).
     *
     * @return list<array{id:int, content:string}>
     */
    public function chunksOf(int $publicationId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, content FROM publication_chunk WHERE publication_id = :p ORDER BY ord',
            ['p' => $publicationId],
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'content' => (string) $r['content']], $rows);
    }

    /**
     * Insère l'empreinte d'un chunk + ses bandes LSH.
     *
     * @param list<int> $bands un hash par bande (index = numéro de bande)
     */
    public function insertFingerprint(int $chunkId, int $publicationId, string $signatureBase64, int $shingleCount, array $bands): void
    {
        $this->conn->insert('chunk_fingerprint', [
            'chunk_id' => $chunkId,
            'publication_id' => $publicationId,
            'signature' => $signatureBase64,
            'shingle_count' => $shingleCount,
        ]);
        $fpId = (int) $this->conn->lastInsertId();

        foreach ($bands as $index => $hash) {
            $this->conn->insert('chunk_fingerprint_band', [
                'fingerprint_id' => $fpId,
                'band_index' => $index,
                'band_hash' => $hash,
            ]);
        }
    }

    /**
     * Publications ayant des chunks plein texte mais PAS encore d'empreintes (drain).
     *
     * @return list<int>
     */
    public function publicationsNeedingFingerprint(int $limit): array
    {
        $rows = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT pc.publication_id
             FROM publication_chunk pc
             WHERE NOT EXISTS (SELECT 1 FROM chunk_fingerprint cf WHERE cf.publication_id = pc.publication_id)
             ORDER BY pc.publication_id
             LIMIT '.max(1, $limit),
        );

        return array_map('intval', $rows);
    }

    /**
     * Publications déjà empreintées (à passer au scan).
     *
     * @return list<int>
     */
    public function publicationsWithFingerprints(int $limit): array
    {
        $rows = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT publication_id FROM chunk_fingerprint ORDER BY publication_id DESC LIMIT '.max(1, $limit),
        );

        return array_map('intval', $rows);
    }

    /**
     * RAPPEL LSH : couples (chunk source de $publicationId, chunk cible d'une AUTRE
     * publication) partageant ≥1 bande. Sous-quadratique grâce à idx_fp_band.
     *
     * @return list<array{srcChunkId:int, tgtChunkId:int, tgtPubId:int}>
     */
    public function candidateChunkPairs(int $publicationId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT DISTINCT sf.chunk_id AS src_chunk_id,
                    tf.chunk_id AS tgt_chunk_id,
                    tf.publication_id AS tgt_pub_id
             FROM chunk_fingerprint sf
             JOIN chunk_fingerprint_band sb ON sb.fingerprint_id = sf.id
             JOIN chunk_fingerprint_band tb
                  ON tb.band_index = sb.band_index AND tb.band_hash = sb.band_hash
                 AND tb.fingerprint_id <> sf.id
             JOIN chunk_fingerprint tf ON tf.id = tb.fingerprint_id
             WHERE sf.publication_id = :p AND tf.publication_id <> :p',
            ['p' => $publicationId],
        );

        return array_map(static fn (array $r): array => [
            'srcChunkId' => (int) $r['src_chunk_id'],
            'tgtChunkId' => (int) $r['tgt_chunk_id'],
            'tgtPubId' => (int) $r['tgt_pub_id'],
        ], $rows);
    }

    /**
     * Contenu de chunks par id (pour le scoring exact).
     *
     * @param list<int> $chunkIds
     *
     * @return array<int, string> id => content
     */
    public function chunkContents(array $chunkIds): array
    {
        if ([] === $chunkIds) {
            return [];
        }
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, content FROM publication_chunk WHERE id IN (:ids)',
            ['ids' => array_values(array_unique(array_map('intval', $chunkIds)))],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['content'];
        }

        return $out;
    }
}
