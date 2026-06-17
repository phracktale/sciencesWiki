<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Données du tableau de bord admin : volumétrie du corpus (global + par domaine
 * racine), base de données (moteur/version/taille/enregistrements), système
 * (OS/PHP/serveur web/DB), disque.
 */
final class AdminDashboardController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/admin/dashboard', name: 'admin_dashboard_data', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $conn = $this->em->getConnection();

        // --- Corpus global ---
        $publications = (int) $conn->executeQuery('SELECT count(*) FROM publication')->fetchOne();
        $answers = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status IN ('valide','non_relu')")->fetchOne();
        $questions = (int) $conn->executeQuery('SELECT count(*) FROM question')->fetchOne();
        $treeNodes = (int) $conn->executeQuery('SELECT count(*) FROM tree_node')->fetchOne();

        // Snapshot du jour (progression dans le temps) + série des 30 derniers jours.
        $conn->executeStatement(
            'INSERT INTO daily_stat (day, publications, answers, questions) VALUES (CURRENT_DATE, :p, :a, :q)
             ON CONFLICT (day) DO UPDATE SET publications = :p, answers = :a, questions = :q',
            ['p' => $publications, 'a' => $answers, 'q' => $questions],
        );
        $history = $conn->executeQuery(
            'SELECT day::text AS day, publications, answers, questions FROM daily_stat ORDER BY day DESC LIMIT 30'
        )->fetchAllAssociative();
        $history = array_reverse($history);

        // --- Par domaine racine (niveau 0) : publications du nœud + descendants ---
        $roots = [];
        foreach ($conn->executeQuery('SELECT slug, label FROM tree_node WHERE level = 0 ORDER BY label')->fetchAllAssociative() as $root) {
            $count = (int) $conn->executeQuery(
                'WITH RECURSIVE sub AS (
                    SELECT id FROM tree_node WHERE slug = :slug
                    UNION SELECT e.child_id FROM tree_edge e JOIN sub ON e.parent_id = sub.id
                 ) SELECT count(DISTINCT ps.publication_id) FROM placement_suggestion ps WHERE ps.tree_node_id IN (SELECT id FROM sub)',
                ['slug' => $root['slug']],
            )->fetchOne();
            $roots[] = ['slug' => $root['slug'], 'label' => $root['label'], 'publications' => $count];
        }

        // --- Base de données ---
        $dbVersion = (string) $conn->executeQuery('SHOW server_version')->fetchOne();
        $dbSize = (int) $conn->executeQuery('SELECT pg_database_size(current_database())')->fetchOne();
        $tables = [];
        foreach (['publication', 'tree_node', 'tree_edge', 'question', 'answer', 'answer_revision', 'footnote', 'placement_suggestion', 'author', 'authorship', '"user"'] as $t) {
            try {
                $tables[trim($t, '"')] = (int) $conn->executeQuery('SELECT count(*) FROM '.$t)->fetchOne();
            } catch (\Throwable) {
                // table absente : ignore
            }
        }

        // --- Système ---
        $fs = '/app';
        $diskTotal = @disk_total_space($fs) ?: 0;
        $diskFree = @disk_free_space($fs) ?: 0;

        return new JsonResponse([
            'corpus' => [
                'publications' => $publications,
                'answers' => $answers,
                'questions' => $questions,
                'treeNodes' => $treeNodes,
                'roots' => $roots,
            ],
            'database' => [
                'engine' => 'PostgreSQL + pgvector',
                'version' => $dbVersion,
                'sizeBytes' => $dbSize,
                'tables' => $tables,
            ],
            'system' => [
                'os' => php_uname('s').' '.php_uname('r'),
                'php' => \PHP_VERSION,
                'webServer' => $_SERVER['SERVER_SOFTWARE'] ?? 'FrankenPHP',
                'diskTotalBytes' => (int) $diskTotal,
                'diskUsedBytes' => (int) ($diskTotal - $diskFree),
            ],
            'history' => $history,
        ]);
    }
}
