<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Graphe 3D des publications pour le visualiseur (three.js / 3d-force-graph).
 * Nœuds = publications (taille ∝ citations, couleur ∝ domaine). Arêtes =
 * SIMILARITÉ SÉMANTIQUE (embeddings pgvector), en deux paliers stylés
 * différemment : « fort » (très similaire) vs « faible » (modérément).
 * NB : les arêtes de CITATION ne sont pas stockées en base (referenced_works
 * non ingérés) → on s'appuie sur la similarité, source de données disponible.
 * ROLE_ADMIN (firewall /api/admin).
 */
final class AdminGraph3dController
{
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT = 400;
    private const TOP_K = 6;          // voisins max par nœud (lisibilité)
    private const STRONG = 0.80;      // cosinus ≥ → lien « fort »
    private const WEAK = 0.62;        // cosinus ≥ → lien « faible »

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/admin/graph3d', name: 'admin_graph3d', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $conn = $this->em->getConnection();
        $limit = min(self::MAX_LIMIT, max(20, (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT)));
        $domain = trim((string) $request->query->get('domain', ''));

        $params = [];
        $domainFilter = '';
        if ('' !== $domain) {
            $domainFilter = 'AND EXISTS (SELECT 1 FROM placement_suggestion ps
                 JOIN tree_node tn ON tn.id = ps.tree_node_id
                 WHERE ps.publication_id = p.id AND tn.domain = :domain)';
            $params['domain'] = $domain;
        }

        // CTE : on choisit d'abord les N plus cités AYANT un embedding (index
        // idx_pub_cited), PUIS on calcule les latéraux (domaine, auteurs) sur ces
        // N lignes seulement — pas sur tout le corpus.
        $rows = $conn->fetchAllAssociative(
            "WITH picked AS (
                 SELECT p.id, p.title, p.oa_status, p.type, p.cited_by_count,
                        p.doi, p.oa_url, p.landing_page_url, p.publication_date, p.embedding
                 FROM publication p
                 WHERE p.embedding IS NOT NULL
                 $domainFilter
                 ORDER BY p.cited_by_count DESC NULLS LAST
                 LIMIT $limit
             )
             SELECT p.id, p.title, p.oa_status, p.type, p.cited_by_count,
                    p.doi, p.oa_url, p.landing_page_url,
                    EXTRACT(YEAR FROM p.publication_date)::int AS year,
                    dom.domain AS domain,
                    auth.names AS authors,
                    p.embedding::text AS emb
             FROM picked p
             LEFT JOIN LATERAL (
                 SELECT tn.domain FROM placement_suggestion ps
                 JOIN tree_node tn ON tn.id = ps.tree_node_id
                 WHERE ps.publication_id = p.id AND tn.domain IS NOT NULL
                 ORDER BY ps.score DESC LIMIT 1
             ) dom ON true
             LEFT JOIN LATERAL (
                 SELECT string_agg(a.name, ', ' ORDER BY au.position) AS names
                 FROM (SELECT author_id, position FROM authorship WHERE publication_id = p.id ORDER BY position LIMIT 6) au
                 JOIN author a ON a.id = au.author_id
             ) auth ON true
             ORDER BY p.cited_by_count DESC NULLS LAST",
            $params,
        );

        $nodes = [];
        $vecs = [];
        foreach ($rows as $r) {
            $vec = $this->parseVector((string) $r['emb']);
            if (null === $vec) {
                continue;
            }
            $id = (int) $r['id'];
            $nodes[] = [
                'id' => $id,
                'title' => (string) $r['title'],
                'domain' => $r['domain'] !== null ? (string) $r['domain'] : null,
                'authors' => $r['authors'] !== null ? (string) $r['authors'] : null,
                'year' => $r['year'] !== null ? (int) $r['year'] : null,
                'oaStatus' => (string) $r['oa_status'],
                'type' => $r['type'] !== null ? (string) $r['type'] : null,
                'citations' => (int) $r['cited_by_count'],
                'url' => $this->bestUrl($r),
            ];
            $vecs[$id] = $this->normalize($vec);
        }

        $links = $this->similarityLinks($nodes, $vecs);

        $domains = $conn->fetchFirstColumn('SELECT DISTINCT domain FROM tree_node WHERE domain IS NOT NULL ORDER BY domain');

        return new JsonResponse([
            'nodes' => $nodes,
            'links' => $links,
            'domains' => $domains,
            'meta' => [
                'count' => \count($nodes),
                'links' => \count($links),
                'limit' => $limit,
                'domain' => '' !== $domain ? $domain : null,
                'thresholds' => ['strong' => self::STRONG, 'weak' => self::WEAK],
                'note' => 'Liens = similarité sémantique (embeddings). Les citations ne sont pas encore ingérées comme arêtes.',
            ],
        ]);
    }

    /**
     * kNN cosinus en mémoire (N ≤ 400 → trivial). Garde les TOP_K meilleurs
     * voisins par nœud au-dessus du seuil faible ; dédoublonne les paires.
     *
     * @param list<array<string,mixed>>  $nodes
     * @param array<int,list<float>>     $vecs
     *
     * @return list<array{source:int,target:int,kind:string,value:float}>
     */
    private function similarityLinks(array $nodes, array $vecs): array
    {
        $ids = array_map(static fn (array $n): int => (int) $n['id'], $nodes);
        $byNode = [];
        foreach ($ids as $a) {
            $va = $vecs[$a] ?? null;
            if (null === $va) {
                continue;
            }
            $sims = [];
            foreach ($ids as $b) {
                if ($b === $a) {
                    continue;
                }
                $vb = $vecs[$b] ?? null;
                if (null === $vb) {
                    continue;
                }
                $cos = $this->dot($va, $vb);
                if ($cos >= self::WEAK) {
                    $sims[$b] = $cos;
                }
            }
            arsort($sims);
            $byNode[$a] = \array_slice($sims, 0, self::TOP_K, true);
        }

        $seen = [];
        $links = [];
        foreach ($byNode as $a => $sims) {
            foreach ($sims as $b => $cos) {
                $key = $a < $b ? $a.'-'.$b : $b.'-'.$a;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $links[] = [
                    'source' => (int) $a,
                    'target' => (int) $b,
                    'kind' => $cos >= self::STRONG ? 'strong' : 'weak',
                    'value' => round($cos, 3),
                ];
            }
        }

        return $links;
    }

    /** @return list<float>|null */
    private function parseVector(string $text): ?array
    {
        $text = trim($text, "[] \t\n\r");
        if ('' === $text) {
            return null;
        }
        $parts = explode(',', $text);
        $out = [];
        foreach ($parts as $p) {
            $out[] = (float) $p;
        }

        return [] === $out ? null : $out;
    }

    /**
     * @param list<float> $v
     *
     * @return list<float>
     */
    private function normalize(array $v): array
    {
        $n = 0.0;
        foreach ($v as $x) {
            $n += $x * $x;
        }
        $n = sqrt($n);
        if ($n <= 0.0) {
            return $v;
        }
        foreach ($v as $i => $x) {
            $v[$i] = $x / $n;
        }

        return $v;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function dot(array $a, array $b): float
    {
        $s = 0.0;
        $len = min(\count($a), \count($b));
        for ($i = 0; $i < $len; ++$i) {
            $s += $a[$i] * $b[$i];
        }

        return $s;
    }

    /** @param array<string,mixed> $r */
    private function bestUrl(array $r): ?string
    {
        if (!empty($r['oa_url'])) {
            return (string) $r['oa_url'];
        }
        if (!empty($r['landing_page_url'])) {
            return (string) $r['landing_page_url'];
        }
        if (!empty($r['doi'])) {
            return 'https://doi.org/'.$r['doi'];
        }

        return null;
    }
}
