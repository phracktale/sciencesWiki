<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques publiques du corpus : volume global, dernière mise à jour,
 * derniers papiers moissonnés, et volume par branche (nœud + descendants).
 */
final class StatsController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function global(): JsonResponse
    {
        $conn = $this->em->getConnection();

        $total = (int) $conn->executeQuery('SELECT count(*) FROM publication')->fetchOne();
        $placed = (int) $conn->executeQuery('SELECT count(DISTINCT publication_id) FROM placement_suggestion')->fetchOne();
        $answers = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status IN ('valide','non_relu')")->fetchOne();
        $validated = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status = 'valide'")->fetchOne();
        $questions = (int) $conn->executeQuery('SELECT count(*) FROM question')->fetchOne();
        $fulltext = (int) ($conn->executeQuery('SELECT count(DISTINCT publication_id) FROM publication_chunk')->fetchOne() ?: 0);
        $lastUpdate = $conn->executeQuery('SELECT max(updated_at) FROM publication')->fetchOne();

        $recent = $conn->executeQuery(
            'SELECT title, doi, publication_date FROM publication ORDER BY created_at DESC LIMIT 5'
        )->fetchAllAssociative();

        return new JsonResponse([
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
        ]);
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
