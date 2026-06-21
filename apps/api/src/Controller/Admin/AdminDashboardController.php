<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
    public function __invoke(Request $request): JsonResponse
    {
        $conn = $this->em->getConnection();

        // Filtre optionnel par type de publication (article, preprint, book…).
        // Il scope la VOLUMÉTRIE DU CORPUS + les indicateurs détaillés liés aux
        // publications ; les blocs structure/base/système restent globaux.
        $type = trim((string) $request->query->get('type', ''));
        $hasType = '' !== $type;
        $tWhere = $hasType ? ' WHERE type = :t' : '';   // requêtes sans autre filtre
        $tAnd = $hasType ? ' AND type = :t' : '';       // requêtes avec déjà un WHERE
        $tp = $hasType ? ['t' => $type] : [];           // paramètres liés

        // --- Corpus global (filtré par type le cas échéant) ---
        $publications = (int) $conn->executeQuery('SELECT count(*) FROM publication'.$tWhere, $tp)->fetchOne();
        $answers = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status IN ('valide','non_relu')")->fetchOne();
        $questions = (int) $conn->executeQuery('SELECT count(*) FROM question')->fetchOne();
        $treeNodes = (int) $conn->executeQuery('SELECT count(*) FROM tree_node')->fetchOne();

        // Snapshot du jour (progression dans le temps) + série des 30 derniers jours.
        // Uniquement sur les chiffres GLOBAUX (jamais filtrés) pour ne pas polluer l'historique.
        if (!$hasType) {
            $conn->executeStatement(
                'INSERT INTO daily_stat (day, publications, answers, questions) VALUES (CURRENT_DATE, :p, :a, :q)
                 ON CONFLICT (day) DO UPDATE SET publications = :p, answers = :a, questions = :q',
                ['p' => $publications, 'a' => $answers, 'q' => $questions],
            );
        }
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

        // --- Indicateurs détaillés demandés (filtrés par type le cas échéant) ---
        $freeFullArticles = (int) $conn->executeQuery("SELECT count(*) FROM publication WHERE oa_status NOT IN ('closed','unknown')".$tAnd, $tp)->fetchOne();
        $paywalled = (int) $conn->executeQuery("SELECT count(*) FROM publication WHERE oa_status = 'closed'".$tAnd, $tp)->fetchOne();
        // Texte intégral consultable : fragments présents (jointure sur publication si filtre type).
        $pdfConsultables = $hasType
            ? (int) $conn->executeQuery('SELECT count(DISTINCT pc.publication_id) FROM publication_chunk pc JOIN publication p ON p.id = pc.publication_id WHERE p.type = :t', $tp)->fetchOne()
            : (int) $conn->executeQuery('SELECT count(DISTINCT publication_id) FROM publication_chunk')->fetchOne();
        // Texte intégral converti (TEI/pdftotext) + vectorisé vs résumé seul.
        $fulltextVectorized = $pdfConsultables;
        // Résumé seul = (articles avec embedding) − (articles avec texte intégral).
        // Arithmétique plutôt qu'un NOT IN (trop lent sur publication_chunk volumineux).
        $embeddingTotal = (int) $conn->executeQuery('SELECT count(*) FROM publication WHERE embedding IS NOT NULL'.$tAnd, $tp)->fetchOne();
        $abstractOnly = max(0, $embeddingTotal - $fulltextVectorized);
        // NOT EXISTS (indexé sur publication_id) au lieu de NOT IN.
        $fulltextRetryable = (int) $conn->executeQuery("SELECT count(*) FROM publication p WHERE p.fulltext_fetched_at IS NOT NULL AND p.oa_url IS NOT NULL AND p.oa_url <> '' AND NOT EXISTS (SELECT 1 FROM publication_chunk pc WHERE pc.publication_id = p.id)".$tAnd, $tp)->fetchOne();
        $fulltextGrobid = (int) $conn->executeQuery("SELECT count(*) FROM publication WHERE fulltext_source = 'grobid_self'".$tAnd, $tp)->fetchOne();
        $authorsCount = (int) $conn->executeQuery('SELECT count(*) FROM author')->fetchOne();
        $publishersCount = (int) $conn->executeQuery('SELECT count(*) FROM publisher')->fetchOne();
        $journalsCount = (int) $conn->executeQuery('SELECT count(*) FROM journal')->fetchOne();
        $topPublishers = $conn->executeQuery(
            'SELECT p.name, count(j.id) AS journals
               FROM publisher p JOIN journal j ON j.publisher_id = p.id
              GROUP BY p.id, p.name ORDER BY journals DESC, p.name LIMIT 10'
        )->fetchAllAssociative();

        // Réponses : validées humain vs encore « IA seule » (non relues).
        $answersValidated = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status = 'valide'")->fetchOne();
        $answersAi = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status = 'non_relu'")->fetchOne();
        // Questions : proposées par l'IA vs posées par un humain.
        $questionsAi = (int) $conn->executeQuery("SELECT count(*) FROM question WHERE origin = 'suggeree_ia'")->fetchOne();
        $questionsHuman = (int) $conn->executeQuery("SELECT count(*) FROM question WHERE origin = 'libre_utilisateur'")->fetchOne();
        // Total ≈ disponible chez OpenAlex (somme des meta.count par rubrique moissonnée ;
        // surcompte les articles présents dans plusieurs rubriques → indicatif).
        $openAlexTotal = (int) $conn->executeQuery("SELECT COALESCE(SUM(value::bigint),0) FROM setting WHERE name LIKE 'openalex.total.%' AND value ~ '^[0-9]+$'")->fetchOne();

        // Répartition du corpus par type (toujours globale : aide à choisir le filtre).
        $typeBreakdown = $conn->executeQuery(
            "SELECT COALESCE(NULLIF(type, ''), '(inconnu)') AS type, count(*) AS n
               FROM publication GROUP BY 1 ORDER BY n DESC"
        )->fetchAllAssociative();

        // --- Base de données ---
        $dbVersion = (string) $conn->executeQuery('SHOW server_version')->fetchOne();
        $dbSize = (int) $conn->executeQuery('SELECT pg_database_size(current_database())')->fetchOne();
        $tables = [];
        foreach (['publication', 'tree_node', 'tree_edge', 'question', 'answer', 'answer_revision', 'footnote', 'placement_suggestion', 'author', 'authorship', 'app_user'] as $t) {
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
            'typeFilter' => $type,
            'availableTypes' => ['article', 'preprint', 'review', 'book', 'book-chapter', 'dataset', 'dissertation', 'report', 'editorial', 'letter', 'reference-entry', 'standard', 'peer-review', 'erratum', 'other'],
            'typeBreakdown' => $typeBreakdown,
            'metrics' => [
                'freeFullArticles' => $freeFullArticles,
                'paywalled' => $paywalled,
                'pdfConsultables' => $pdfConsultables,
                'fulltextVectorized' => $fulltextVectorized,
                'fulltextGrobid' => $fulltextGrobid,
                'abstractOnly' => $abstractOnly,
                'fulltextRetryable' => $fulltextRetryable,
                'authors' => $authorsCount,
                'publishers' => $publishersCount,
                'journals' => $journalsCount,
                'topPublishers' => $topPublishers,
                'answersValidated' => $answersValidated,
                'answersAi' => $answersAi,
                'questionsAi' => $questionsAi,
                'questionsHuman' => $questionsHuman,
                'openAlexTotal' => $openAlexTotal,
            ],
        ]);
    }
}
