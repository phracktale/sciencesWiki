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
                // (a) URL OA directe, ou (b) sans oa_url mais DOI bien cité (≥20) → repli
                // résolveur (CORE/Europe PMC). Cohérent avec app:fulltext:enqueue.
                "SELECT id FROM publication
                 WHERE fulltext_fetched_at IS NULL
                   AND ( (oa_url IS NOT NULL AND oa_url <> '')
                         OR (doi IS NOT NULL AND cited_by_count >= 20) )
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
    /**
     * @param list<float>  $embedding
     * @param list<string> $types restreint aux types de publication donnés (vide = tous, hors satellites)
     */
    public function nearestTo(array $embedding, int $k, array $types = []): array
    {
        $result = [];
        foreach ($this->vectorCandidates($embedding, max(1, $k), $types) as $row) {
            $publication = $this->find($row['id']);
            if (null !== $publication) {
                $result[] = ['publication' => $publication, 'distance' => $row['distance']];
            }
        }

        return $result;
    }

    /**
     * Récupération HYBRIDE : kNN vectoriel (sémantique) + plein-texte (lexical),
     * fusionnés par Reciprocal Rank Fusion. Corrige le défaut de rappel du vecteur
     * seul : quand un terme générique domine l'embedding (« marqueurs biologiques »),
     * le signal lexical (« covid ») fait remonter les publications réellement
     * pertinentes même si leur distance vectorielle est élevée. L'unité = la publication.
     *
     * @param list<float> $embedding
     *
     * @return list<array{publication: Publication, distance: ?float, score: float, lexical: bool}>
     */
    public function nearestHybrid(array $embedding, string $query, int $k, float $maxDistance): array
    {
        $k = max(1, $k);
        $cand = min($k * 4, 80);

        // 1) Vectoriel : on ne retient que les voisins sémantiquement proches (≤ seuil).
        $vector = [];
        foreach ($this->vectorCandidates($embedding, $cand) as $row) {
            if ($row['distance'] <= $maxDistance) {
                $vector[$row['id']] = $row['distance'];
            }
        }

        // 2) Lexical : plein-texte OR — un seul terme discriminant (« covid »)
        //    suffit à faire remonter une publication.
        $lexical = $this->lexicalCandidates($query, $cand); // id => position (0-based)

        // 3) Fusion RRF : score = Σ 1/(K + rang) sur chaque classement.
        $K = 60;
        $scores = [];
        $vPos = 0;
        foreach (array_keys($vector) as $id) { // déjà trié par distance asc
            $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($K + $vPos + 1);
            ++$vPos;
        }
        foreach ($lexical as $id => $pos) {
            $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($K + $pos + 1);
        }
        arsort($scores);

        $out = [];
        foreach (\array_slice(array_keys($scores), 0, $k) as $id) {
            $publication = $this->find($id);
            if (null === $publication) {
                continue;
            }
            $out[] = [
                'publication' => $publication,
                'distance' => $vector[$id] ?? null,
                'score' => $scores[$id],
                'lexical' => isset($lexical[$id]),
            ];
        }

        return $out;
    }

    /**
     * kNN vectoriel combiné (résumé + fragments de texte intégral), trié par
     * distance croissante. Chaque sous-requête « ORDER BY embedding <=> const
     * LIMIT n » exploite l'index HNSW ; on fusionne en retenant la meilleure
     * distance par publication (un corps précis l'emporte sur un résumé moins
     * proche), filtre rétractations/satellites, puis limite.
     *
     * @param list<float> $embedding
     *
     * @return list<array{id: int, distance: float}>
     */
    private function vectorCandidates(array $embedding, int $limit, array $types = []): array
    {
        $literal = (string) new Vector($embedding);
        $limit = max(1, $limit);
        $typeClause = [] !== $types ? ' AND p.type IN (:ptypes)' : '';
        $params = ['vec' => $literal];
        $paramTypes = [];
        if ([] !== $types) {
            $params['ptypes'] = array_values($types);
            $paramTypes['ptypes'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }
        // Sur-échantillonnage : marge pour la déduplication (un article peut sortir
        // des deux côtés) et le filtre rétraction appliqué ensuite.
        $perSide = min($limit * 4, 120);

        $conn = $this->getEntityManager()->getConnection();
        // ef_search ≥ taille demandée : qualité du kNN approximatif HNSW.
        $conn->executeStatement('SET hnsw.ef_search = '.max(40, $perSide));

        $rows = $conn->executeQuery(
            \sprintf(
                "WITH abs AS (
                    SELECT id, embedding <=> CAST(:vec AS vector) AS distance
                    FROM publication
                    ORDER BY embedding <=> CAST(:vec AS vector), id
                    LIMIT %1\$d
                 ), chk AS (
                    SELECT publication_id AS id, embedding <=> CAST(:vec AS halfvec) AS distance
                    FROM publication_chunk
                    ORDER BY embedding <=> CAST(:vec AS halfvec), publication_id
                    LIMIT %1\$d
                 ), merged AS (
                    SELECT id, MIN(distance) AS distance
                    FROM (SELECT id, distance FROM abs UNION ALL SELECT id, distance FROM chk) AS u
                    GROUP BY id
                 )
                 SELECT m.id, m.distance
                 FROM merged m
                 JOIN publication p ON p.id = m.id
                 WHERE p.retraction_status = 'none' AND p.".\App\Catalog\PublicationType::notSatelliteSql().$typeClause."
                 ORDER BY m.distance ASC, m.id ASC
                 LIMIT %2\$d",
                $perSide,
                $limit,
            ),
            $params,
            $paramTypes,
        )->fetchAllAssociative();

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'distance' => (float) $r['distance']],
            $rows,
        );
    }

    /**
     * Meilleur passage (chunk de texte intégral) d'une publication vis-à-vis d'un
     * embedding de requête — sert au « locator » : montrer derrière chaque source [n]
     * l'extrait exact qui la justifie. kNN sur les seuls chunks de cette publication
     * (peu nombreux, filtrés par idx_chunk_publication) → rapide. Null si pas de
     * texte intégral ingéré (on retombera sur le résumé côté appelant).
     *
     * @param list<float> $embedding
     */
    public function bestPassageFor(int $publicationId, array $embedding): ?string
    {
        return $this->topPassagesFor($publicationId, $embedding, 1)[0] ?? null;
    }

    /**
     * Les N meilleurs passages (chunks de texte intégral) d'une publication vis-à-vis
     * d'un embedding, du plus proche au moins proche. Sert au locator (extrait affiché)
     * ET à la vérification de fidélité contre le VRAI texte cité (cf. FaithfulnessChecker).
     * Vide si pas de texte intégral ingéré.
     *
     * @param list<float> $embedding
     *
     * @return list<string>
     */
    public function topPassagesFor(int $publicationId, array $embedding, int $n = 2): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            \sprintf(
                'SELECT content FROM publication_chunk
                 WHERE publication_id = :p
                 ORDER BY embedding <=> CAST(:vec AS halfvec)
                 LIMIT %d',
                max(1, $n),
            ),
            ['p' => $publicationId, 'vec' => (string) new Vector($embedding)],
        )->fetchFirstColumn();

        return array_map(static fn ($c): string => (string) $c, $rows);
    }

    /**
     * Candidats lexicaux (plein-texte OR sur titre + résumé), classés par ts_rank.
     * Réutilise EXACTEMENT l'expression to_tsvector('simple', …) indexée (GIN) afin
     * d'exploiter l'index. La requête est transformée en tsquery OR sécurisé.
     *
     * @return array<int, int> id de publication => position (0-based)
     */
    private function lexicalCandidates(string $query, int $limit): array
    {
        $tsq = $this->ftsOrQuery($query);
        if ('' === $tsq) {
            return [];
        }
        $tsv = "to_tsvector('simple', coalesce(p.title,'') || ' ' || coalesce(p.abstract,''))";
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            \sprintf(
                "SELECT p.id
                 FROM publication p
                 WHERE p.retraction_status = 'none' AND p.%s
                   AND %s @@ to_tsquery('simple', :tsq)
                 ORDER BY ts_rank(%s, to_tsquery('simple', :tsq)) DESC, p.cited_by_count DESC
                 LIMIT %d",
                \App\Catalog\PublicationType::notSatelliteSql(),
                $tsv,
                $tsv,
                max(1, $limit),
            ),
            ['tsq' => $tsq],
        )->fetchFirstColumn();

        $out = [];
        foreach (array_values($ids) as $pos => $id) {
            $out[(int) $id] = $pos;
        }

        return $out;
    }

    /**
     * Construit un tsquery OR sécurisé à partir d'une requête libre : on ne garde
     * que des lexèmes alphanumériques (≥ 3 car., hors mots vides), joints par « | ».
     * Sécurisé par construction (aucun opérateur tsquery ne survit au découpage).
     */
    private function ftsOrQuery(string $query): string
    {
        static $stop = [
            'les', 'des', 'une', 'un', 'le', 'la', 'de', 'du', 'et', 'ou', 'pour', 'par',
            'sur', 'dans', 'aux', 'est', 'sont', 'que', 'qui', 'quel', 'quels', 'quelle',
            'quelles', 'avec', 'the', 'and', 'for', 'what', 'are', 'was', 'were', 'with',
        ];
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 3 && !\in_array($w, $stop, true)) {
                $terms[$w] = true;
            }
        }

        return implode(' | ', array_keys($terms));
    }

    /**
     * Publications placées dans un nœud — périmètre de l'analyse de controverses
     * (cf. spec controverses §0.1 / §6.1). On retient tout placement NON rejeté
     * (les suggestions kNN restent « proposed » tant qu'un humain ne tranche pas ;
     * c'est aussi le périmètre de searchInSubtree). Hors rétractations.
     *
     * @return list<Publication>
     */
    public function findPlacedInNode(int $nodeId, int $limit): array
    {
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            \sprintf(
                "SELECT p.id
                 FROM publication p
                 JOIN placement_suggestion ps ON ps.publication_id = p.id
                 WHERE ps.tree_node_id = :node AND ps.status <> 'rejected'
                   AND p.retraction_status = 'none'
                 ORDER BY p.cited_by_count DESC, p.id DESC
                 LIMIT %d",
                max(1, $limit),
            ),
            ['node' => $nodeId],
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

    /** Nombre de publications placées (non rejetées) directement dans un nœud. */
    public function countPlacedInNode(int $nodeId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT count(*)
             FROM placement_suggestion ps
             JOIN publication p ON p.id = ps.publication_id
             WHERE ps.tree_node_id = :node AND ps.status <> 'rejected'
               AND p.retraction_status = 'none'",
            ['node' => $nodeId],
        )->fetchOne();
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
    public function searchInSubtree(string $slug, string $query, bool $stemming, int $page, int $perPage, string $sort = '', string $dir = 'asc', array $types = []): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $offset = max(0, ($page - 1) * $perPage);
        $hasQuery = '' !== trim($query);
        $config = $stemming ? 'english' : 'simple';
        $d = 'desc' === strtolower($dir) ? 'DESC' : 'ASC';

        // Front : on ne remonte que les types demandés (satellites retirés) ;
        // par défaut, uniquement les papiers de recherche primaires.
        $searchTypes = \App\Catalog\PublicationType::searchTypes($types);

        // Sous-arbre résolu UNE SEULE FOIS : si on laisse la CTE récursive dans
        // l'EXISTS corrélé, PostgreSQL la ré-évalue par ligne candidate (×100k+)
        // → timeout. On passe les ids des nœuds en paramètre.
        $nodeIds = array_map('intval', $conn->executeQuery(
            'WITH RECURSIVE sub AS (
                SELECT id FROM tree_node WHERE slug = :slug
                UNION SELECT e.child_id FROM tree_edge e JOIN sub ON e.parent_id = sub.id
             ) SELECT id FROM sub',
            ['slug' => $slug],
        )->fetchFirstColumn());
        if ([] === $nodeIds) {
            return ['items' => [], 'total' => 0];
        }

        $params = ['nodes' => $nodeIds, 'ptypes' => $searchTypes];
        $paramTypes = [
            'nodes' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            'ptypes' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ];
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
              AND p.type IN (:ptypes)
              AND EXISTS (
                SELECT 1 FROM placement_suggestion ps
                 WHERE ps.publication_id = p.id AND ps.tree_node_id IN (:nodes)
              )
              $ftsWhere";

        $total = (int) $conn->executeQuery("SELECT count(*) $base", $params, $paramTypes)->fetchOne();

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
            $paramTypes,
        )->fetchAllAssociative();

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * Recherche en LANGAGE NATUREL scopée à un sous-arbre : kNN vectoriel (filtré au
     * sous-arbre) + plein-texte OR, fusionnés par RRF, classés par pertinence
     * sémantique. Même forme de lignes riches que searchInSubtree (pagination incluse).
     *
     * @param list<float> $embedding
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function searchInSubtreeHybrid(string $slug, array $embedding, string $query, int $page, int $perPage, array $types = []): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $searchTypes = \App\Catalog\PublicationType::searchTypes($types);

        $nodeIds = array_map('intval', $conn->executeQuery(
            'WITH RECURSIVE sub AS (
                SELECT id FROM tree_node WHERE slug = :slug
                UNION SELECT e.child_id FROM tree_edge e JOIN sub ON e.parent_id = sub.id
             ) SELECT id FROM sub',
            ['slug' => $slug],
        )->fetchFirstColumn());
        if ([] === $nodeIds) {
            return ['items' => [], 'total' => 0];
        }

        $cand = 200; // pool de candidats par côté avant fusion
        $params = ['nodes' => $nodeIds, 'ptypes' => $searchTypes];
        $paramTypes = [
            'nodes' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            'ptypes' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ];
        // Périmètre commun : non rétracté, types demandés, placé dans le sous-arbre.
        $subtree = "p.retraction_status = 'none' AND p.type IN (:ptypes)
            AND EXISTS (SELECT 1 FROM placement_suggestion ps
                        WHERE ps.publication_id = p.id AND ps.tree_node_id IN (:nodes))";

        // Côté vectoriel (kNN filtré au sous-arbre).
        $conn->executeStatement('SET hnsw.ef_search = '.max(40, $cand));
        $vecIds = $conn->executeQuery(
            \sprintf(
                "SELECT p.id FROM publication p
                 WHERE %s AND p.embedding IS NOT NULL
                 ORDER BY p.embedding <=> CAST(:vec AS vector)
                 LIMIT %d",
                $subtree,
                $cand,
            ),
            $params + ['vec' => (string) new Vector($embedding)],
            $paramTypes,
        )->fetchFirstColumn();

        // Côté lexical (plein-texte OR, sous-arbre).
        $lexIds = [];
        $tsq = $this->ftsOrQuery($query);
        if ('' !== $tsq) {
            $tsv = "to_tsvector('simple', coalesce(p.title,'') || ' ' || coalesce(p.abstract,''))";
            $lexIds = $conn->executeQuery(
                \sprintf(
                    "SELECT p.id FROM publication p
                     WHERE %s AND %s @@ to_tsquery('simple', :tsq)
                     ORDER BY ts_rank(%s, to_tsquery('simple', :tsq)) DESC
                     LIMIT %d",
                    $subtree,
                    $tsv,
                    $tsv,
                    $cand,
                ),
                $params + ['tsq' => $tsq],
                $paramTypes,
            )->fetchFirstColumn();
        }

        // Fusion RRF.
        $K = 60;
        $scores = [];
        foreach (array_values($vecIds) as $pos => $id) {
            $scores[(int) $id] = ($scores[(int) $id] ?? 0.0) + 1.0 / ($K + $pos + 1);
        }
        foreach (array_values($lexIds) as $pos => $id) {
            $scores[(int) $id] = ($scores[(int) $id] ?? 0.0) + 1.0 / ($K + $pos + 1);
        }
        arsort($scores);
        $rankedIds = array_keys($scores);

        $total = \count($rankedIds);
        $pageIds = \array_slice($rankedIds, max(0, ($page - 1) * $perPage), $perPage);
        if ([] === $pageIds) {
            return ['items' => [], 'total' => $total];
        }

        // Hydratation des lignes riches, en CONSERVANT l'ordre de pertinence.
        $rows = $conn->executeQuery(
            "SELECT p.id, p.title, p.doi, p.venue, p.oa_status, p.oa_url, p.landing_page_url,
                    j.name AS journal_name,
                    to_char(p.publication_date, 'YYYY') AS year,
                    (SELECT count(*) FROM publication_chunk pc WHERE pc.publication_id = p.id) AS chunks,
                    (SELECT string_agg(a.name, ', ' ORDER BY au.position)
                       FROM authorship au JOIN author a ON a.id = au.author_id
                      WHERE au.publication_id = p.id) AS authors
             FROM publication p
             LEFT JOIN journal j ON j.id = p.journal_id
             WHERE p.id IN (:ids)
             ORDER BY array_position(CAST(:order AS int[]), p.id)",
            ['ids' => $pageIds, 'order' => '{'.implode(',', $pageIds).'}'],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * Recherche par identifiant externe (ex. openalex/arxiv) stocké dans le JSON
     * `external_ids`. Utilise l'opérateur JSON de PostgreSQL.
     */
    public function findOneByExternalId(string $key, string $value): ?Publication
    {
        // La clé est INLINÉE (et non liée via :key) pour que PostgreSQL puisse
        // utiliser l'index d'expression idx_pub_extid_<clé> (external_ids ->> 'clé') :
        // avec un paramètre lié, le planner retombe en scan séquentiel de toute la
        // table. Les clés proviennent d'un ensemble fini contrôlé par les mappers
        // (openalex/doi/arxiv/pmid/pmcid…), jamais d'une saisie utilisateur ; on
        // valide tout de même le format pour écarter toute injection.
        if (1 !== preg_match('/^[a-z0-9_]{1,32}$/', $key)) {
            return null;
        }
        $sql = \sprintf("SELECT id FROM publication WHERE external_ids ->> '%s' = :value LIMIT 1", $key);
        $id = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['value' => $value])
            ->fetchOne();

        return false === $id ? null : $this->find((int) $id);
    }

    /**
     * Texte intégral conservé d'une publication, fragments concaténés dans l'ordre
     * du document (`ord`), borné en caractères. Vide si aucun chunk ingéré. Sert à
     * l'évaluation AXIS (méthodes/résultats indisponibles dans le seul résumé).
     */
    public function fulltextFor(int $publicationId, int $maxChars = 16000): string
    {
        $chunks = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT content FROM publication_chunk WHERE publication_id = :p ORDER BY ord ASC',
            ['p' => $publicationId],
        )->fetchFirstColumn();

        $text = trim(implode("\n\n", array_map(static fn ($c): string => (string) $c, $chunks)));

        return mb_substr($text, 0, $maxChars);
    }
}
