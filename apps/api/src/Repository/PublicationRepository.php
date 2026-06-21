<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publication;
use App\Enum\ProcessingStatus;
use App\Harvester\Pipeline\PublicationLookup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pgvector\Vector;

/**
 * @extends ServiceEntityRepository<Publication>
 */
class PublicationRepository extends ServiceEntityRepository implements PublicationLookup
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Publication::class);
    }

    public function findOneByDoi(string $doi): ?Publication
    {
        return $this->findOneBy(['doi' => $doi]);
    }

    /**
     * Publications ayant un DOI mais pas encore résolues OA (Unpaywall).
     *
     * @return list<Publication>
     */
    public function findNeedingOaResolution(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.doi IS NOT NULL')
            ->andWhere('p.oaResolvedAt IS NULL')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Publications sans embedding (à enrichir).
     *
     * @return list<Publication>
     */
    public function findNeedingEmbedding(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.embedding IS NULL')
            ->andWhere("p.title <> ''")
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Publications en accès libre (oaUrl) dont le texte intégral n'a pas encore
     * été récupéré/vectorisé.
     *
     * @return list<Publication>
     */
    public function findNeedingFulltext(int $limit): array
    {
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            \sprintf(
                // Curation : on traite d'abord ce qui a un TEI GROBID dispo et le plus
                // cité (haut du panier), puis les PDF directs.
                "SELECT id FROM publication
                 WHERE oa_url IS NOT NULL AND oa_url <> '' AND fulltext_fetched_at IS NULL
                 ORDER BY has_grobid_xml DESC, cited_by_count DESC, (oa_url ILIKE '%%.pdf%%') DESC, id DESC LIMIT %d",
                max(1, $limit),
            ),
        )->fetchFirstColumn();

        $out = [];
        foreach ($ids as $id) {
            $pub = $this->find((int) $id);
            if (null !== $pub) {
                $out[] = $pub;
            }
        }

        return $out;
    }

    /**
     * Publications sans revue rattachée mais ayant un identifiant OpenAlex
     * (rattrapage du référentiel éditeurs/revues sur le stock existant).
     *
     * @return list<Publication>
     */
    public function findNeedingJournal(int $limit): array
    {
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            \sprintf(
                "SELECT id FROM publication
                 WHERE journal_id IS NULL AND external_ids->>'openalex' IS NOT NULL
                 ORDER BY id DESC LIMIT %d",
                max(1, $limit),
            ),
        )->fetchFirstColumn();

        $out = [];
        foreach ($ids as $id) {
            $pub = $this->find((int) $id);
            if (null !== $pub) {
                $out[] = $pub;
            }
        }

        return $out;
    }

    /**
     * Publications avec embedding mais pas encore placées dans l'arbre.
     *
     * @return list<Publication>
     */
    public function findNeedingPlacement(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.embedding IS NOT NULL')
            ->andWhere('p.processingStatus = :status')
            ->setParameter('status', ProcessingStatus::Normalized->value)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche sémantique : publications les plus proches d'un embedding
     * (distance cosinus pgvector).
     *
     * @param list<float> $embedding
     *
     * @return list<array{publication:Publication,distance:float}>
     */
    public function nearestTo(array $embedding, int $k): array
    {
        $literal = (string) new Vector($embedding);
        $k = max(1, $k);
        // Sur-échantillonnage par côté : marge pour la déduplication (un article
        // peut sortir des deux côtés) et le filtre rétraction appliqué ensuite.
        $perSide = min($k * 4, 120);

        $conn = $this->getEntityManager()->getConnection();
        // ef_search ≥ taille demandée : qualité du kNN approximatif HNSW.
        $conn->executeStatement('SET hnsw.ef_search = '.max(40, $perSide));

        // Recherche kNN combinée : embedding du résumé (publication) ET fragments
        // de texte intégral (publication_chunk). Chaque sous-requête
        // « ORDER BY embedding <=> const LIMIT n » exploite l'index HNSW (≈ ms
        // au lieu d'un scan séquentiel). On fusionne en retenant la meilleure
        // distance par publication, afin qu'un article dont le corps répond
        // précisément ressorte même si son résumé est moins proche, puis on
        // filtre les rétractations et on limite. L'unité reste la publication.
        $rows = $conn->executeQuery(
            \sprintf(
                "WITH abs AS (
                    SELECT id, embedding <=> CAST(:vec AS vector) AS distance
                    FROM publication
                    ORDER BY embedding <=> CAST(:vec AS vector)
                    LIMIT %1\$d
                 ), chk AS (
                    SELECT publication_id AS id, embedding <=> CAST(:vec AS halfvec) AS distance
                    FROM publication_chunk
                    ORDER BY embedding <=> CAST(:vec AS halfvec)
                    LIMIT %1\$d
                 ), merged AS (
                    SELECT id, MIN(distance) AS distance
                    FROM (SELECT id, distance FROM abs UNION ALL SELECT id, distance FROM chk) AS u
                    GROUP BY id
                 )
                 SELECT m.id, m.distance
                 FROM merged m
                 JOIN publication p ON p.id = m.id
                 WHERE p.retraction_status = 'none' AND p.".\App\Catalog\PublicationType::notSatelliteSql()."
                 ORDER BY m.distance ASC
                 LIMIT %2\$d",
                $perSide,
                $k,
            ),
            ['vec' => $literal],
        )->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $publication = $this->find((int) $row['id']);
            if (null !== $publication) {
                $result[] = ['publication' => $publication, 'distance' => (float) $row['distance']];
            }
        }

        return $result;
    }

    /**
     * Recherche plein-texte simple (titre + résumé).
     *
     * @return list<Publication>
     */
    public function textSearch(string $query, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('LOWER(p.title) LIKE :q OR LOWER(p.abstract) LIKE :q')
            ->setParameter('q', '%'.mb_strtolower($query).'%')
            ->orderBy('p.publicationDate', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche paginée des articles d'un sous-domaine (le nœud + ses descendants),
     * avec recherche plein-texte facultative. Le stemming (racinisation) est
     * activable : config FTS « english » (mots de la même famille rapprochés) ou
     * « simple » (correspondance exacte des tokens). Exclut les études rétractées.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function searchInSubtree(string $slug, string $query, bool $stemming, int $page, int $perPage, string $sort = '', string $dir = 'asc'): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $offset = max(0, ($page - 1) * $perPage);
        $hasQuery = '' !== trim($query);
        $config = $stemming ? 'english' : 'simple';
        $d = 'desc' === strtolower($dir) ? 'DESC' : 'ASC';

        $params = ['slug' => $slug];
        $ftsWhere = '';
        $order = 'p.publication_date DESC NULLS LAST, p.id DESC';
        if ($hasQuery) {
            $params['q'] = $query;
            // Config injectée en LITTÉRAL (liste blanche, pas une entrée utilisateur) :
            // un paramètre empêcherait l'usage de l'index GIN fonctionnel (cf. migration).
            // L'expression DOIT être identique à celle de l'index.
            $tsv = "to_tsvector('$config', coalesce(p.title,'') || ' ' || coalesce(p.abstract,''))";
            $tsq = "plainto_tsquery('$config', :q)";
            $ftsWhere = "AND $tsv @@ $tsq";
            $order = "ts_rank($tsv, $tsq) DESC, p.publication_date DESC NULLS LAST";
        }
        // Tri par colonne (en-têtes cliquables) — prioritaire sur le rang FTS.
        $order = match ($sort) {
            'titre' => "p.title $d NULLS LAST",
            'annee' => "p.publication_date $d NULLS LAST, p.id DESC",
            'revue' => "coalesce(j.name, p.venue) $d NULLS LAST",
            'auteurs' => "authors $d NULLS LAST",
            default => $order,
        };

        $base = "FROM publication p
            LEFT JOIN journal j ON j.id = p.journal_id
            WHERE p.retraction_status = 'none'
              AND p.".\App\Catalog\PublicationType::notSatelliteSql()."
              AND EXISTS (
                WITH RECURSIVE sub AS (
                    SELECT id FROM tree_node WHERE slug = :slug
                    UNION SELECT e.child_id FROM tree_edge e JOIN sub ON e.parent_id = sub.id
                ) SELECT 1 FROM placement_suggestion ps WHERE ps.publication_id = p.id AND ps.tree_node_id IN (SELECT id FROM sub)
              )
              $ftsWhere";

        $total = (int) $conn->executeQuery("SELECT count(*) $base", $params)->fetchOne();

        $rows = $conn->executeQuery(
            "SELECT p.id, p.title, p.doi, p.venue, p.oa_status, p.oa_url, p.landing_page_url,
                    j.name AS journal_name,
                    to_char(p.publication_date, 'YYYY') AS year,
                    (SELECT count(*) FROM publication_chunk pc WHERE pc.publication_id = p.id) AS chunks,
                    (SELECT string_agg(a.name, ', ' ORDER BY au.position)
                       FROM authorship au JOIN author a ON a.id = au.author_id
                      WHERE au.publication_id = p.id) AS authors
             $base
             ORDER BY $order
             LIMIT $perPage OFFSET $offset",
            $params,
        )->fetchAllAssociative();

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * Recherche par identifiant externe (ex. openalex/arxiv) stocké dans le JSON
     * `external_ids`. Utilise l'opérateur JSON de PostgreSQL.
     */
    public function findOneByExternalId(string $key, string $value): ?Publication
    {
        $sql = 'SELECT id FROM publication WHERE external_ids ->> :key = :value LIMIT 1';
        $id = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['key' => $key, 'value' => $value])
            ->fetchOne();

        return false === $id ? null : $this->find((int) $id);
    }
}
