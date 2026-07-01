<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Statistiques publiques du corpus : volume global, dernière mise à jour,
 * derniers papiers moissonnés, et volume par branche (nœud + descendants).
 */
final class StatsController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Volume public du corpus. Ces chiffres changent lentement et impliquent des
     * comptages coûteux sur de très grosses tables (publication, publication_chunk,
     * placement_suggestion) : on met le résultat en cache (TTL 10 min) pour garder la
     * page d'accueil instantanée, et on estime le total de publications via reltuples
     * (catalogue, immédiat) plutôt qu'un count(*) qui scanne toute la table.
     */
    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function global(): JsonResponse
    {
        $payload = $this->cache->get('stats.global.v1', function (ItemInterface $item): array {
            $item->expiresAfter(600);

            return $this->computeGlobal();
        });

        return new JsonResponse($payload);
    }

    /** @return array<string,mixed> */
    private function computeGlobal(): array
    {
        $conn = $this->em->getConnection();

        // Total publications : on lit la MÊME source que le tableau de bord admin (vue
        // matérialisée dashboard_stats, rafraîchie par cron) pour que les deux pages
        // affichent le même chiffre. Replis successifs : estimation reltuples (instantané)
        // puis count(*) exact si la table n'a jamais été analysée.
        $total = 0;
        try {
            $total = (int) ($conn->executeQuery('SELECT publications FROM dashboard_stats LIMIT 1')->fetchOne() ?: 0);
        } catch (\Throwable) {
            $total = 0;
        }
        if ($total <= 0) {
            $total = (int) $conn->executeQuery("SELECT reltuples::bigint FROM pg_class WHERE oid = 'publication'::regclass")->fetchOne();
        }
        if ($total <= 0) {
            $total = (int) $conn->executeQuery('SELECT count(*) FROM publication')->fetchOne();
        }
        $placed = (int) $conn->executeQuery('SELECT count(DISTINCT publication_id) FROM placement_suggestion')->fetchOne();
        $answers = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status IN ('valide','non_relu')")->fetchOne();
        $validated = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status = 'valide'")->fetchOne();
        $questions = (int) $conn->executeQuery('SELECT count(*) FROM question')->fetchOne();
        $fulltext = (int) ($conn->executeQuery('SELECT count(DISTINCT publication_id) FROM publication_chunk')->fetchOne() ?: 0);
        $lastUpdate = $conn->executeQuery('SELECT max(updated_at) FROM publication')->fetchOne();

        $recent = $conn->executeQuery(
            'SELECT title, doi, publication_date FROM publication ORDER BY created_at DESC LIMIT 5'
        )->fetchAllAssociative();

        return [
            'publications' => $total,
            'placedPublications' => $placed,
            'publishedAnswers' => $answers,
            'validatedAnswers' => $validated,
            'questions' => $questions,
            'fulltextPdfs' => $fulltext,
            'lastUpdate' => \is_string($lastUpdate) ? $lastUpdate : null,
            'recent' => array_map(static fn (array $r): array => [
                'title' => $r['title'],
                'doi' => $r['doi'],
                'date' => $r['publication_date'],
            ], $recent),
        ];
    }

    #[Route('/api/tree_nodes/{slug}/corpus', name: 'api_node_corpus', methods: ['GET'])]
    public function nodeCorpus(string $slug): JsonResponse
    {
        $conn = $this->em->getConnection();

        // Nombre de publications rattachées au nœud ET à ses descendants (DAG),
        // dédoublonnées par publication.
        $sql = 'WITH RECURSIVE sub AS (
                    SELECT id FROM tree_node WHERE slug = :slug
                    UNION
                    SELECT e.child_id FROM tree_edge e JOIN sub ON e.parent_id = sub.id
                )
                SELECT count(DISTINCT ps.publication_id)
                FROM placement_suggestion ps
                WHERE ps.tree_node_id IN (SELECT id FROM sub)';

        $count = (int) $conn->executeQuery($sql, ['slug' => $slug])->fetchOne();

        return new JsonResponse(['slug' => $slug, 'publications' => $count]);
    }

    /**
     * Pour chaque sous-rubrique directe d'un nœud : nombre de publications
     * référencées et de questions, comptés sur la sous-rubrique ET ses descendants.
     * Sert à afficher des pastilles sur les cartouches du front.
     */
    #[Route('/api/tree_nodes/{slug}/children-stats', name: 'api_node_children_stats', methods: ['GET'])]
    public function childrenStats(string $slug): JsonResponse
    {
        $conn = $this->em->getConnection();

        // branch(root_id, node_id) : chaque descendant rattaché à la sous-rubrique
        // directe (root_id) dont il dépend. On agrège ensuite par root_id.
        $sql = "WITH RECURSIVE branch AS (
                    SELECT e.child_id AS root_id, e.child_id AS node_id
                    FROM tree_edge e JOIN tree_node p ON p.id = e.parent_id
                    WHERE p.slug = :slug
                    UNION
                    SELECT b.root_id, e2.child_id
                    FROM tree_edge e2 JOIN branch b ON e2.parent_id = b.node_id
                )
                SELECT tn.slug AS slug,
                       COALESCE(pub.cnt, 0) AS publications,
                       COALESCE(q.cnt, 0) AS questions
                FROM tree_node tn
                JOIN tree_edge pe ON pe.child_id = tn.id
                JOIN tree_node pp ON pp.id = pe.parent_id AND pp.slug = :slug
                LEFT JOIN (
                    SELECT b.root_id, count(DISTINCT ps.publication_id) AS cnt
                    FROM branch b JOIN placement_suggestion ps ON ps.tree_node_id = b.node_id
                    GROUP BY b.root_id
                ) pub ON pub.root_id = tn.id
                LEFT JOIN (
                    SELECT b.root_id, count(*) AS cnt
                    FROM branch b JOIN question q ON q.tree_node_id = b.node_id
                    GROUP BY b.root_id
                ) q ON q.root_id = tn.id";

        $stats = [];
        foreach ($conn->executeQuery($sql, ['slug' => $slug])->fetchAllAssociative() as $row) {
            $stats[(string) $row['slug']] = [
                'publications' => (int) $row['publications'],
                'questions' => (int) $row['questions'],
            ];
        }

        return new JsonResponse(['slug' => $slug, 'children' => $stats]);
    }
}
