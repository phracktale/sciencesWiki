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
        $lastUpdate = $conn->executeQuery('SELECT max(updated_at) FROM publication')->fetchOne();

        $recent = $conn->executeQuery(
            'SELECT title, doi, publication_date FROM publication ORDER BY created_at DESC LIMIT 5'
        )->fetchAllAssociative();

        return new JsonResponse([
            'publications' => $total,
            'placedPublications' => $placed,
            'publishedAnswers' => $answers,
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
}
