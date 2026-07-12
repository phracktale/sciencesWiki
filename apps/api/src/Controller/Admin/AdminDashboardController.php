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

        // Filtre optionnel par type(s) de publication (cases à cocher par famille).
        // Il scope la VOLUMÉTRIE DU CORPUS + les indicateurs détaillés liés aux
        // publications ; les blocs structure/base/système restent globaux.
        // Accepte ?types[]=article&types[]=preprint et, par compat, ?type=article.
        $types = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            array_merge($request->query->all('types'), [$request->query->get('type', '')]),
        )));
        $hasType = [] !== $types;
        $tWhere = $hasType ? ' WHERE type IN (:types)' : '';   // requêtes sans autre filtre
        $tAnd = $hasType ? ' AND type IN (:types)' : '';       // requêtes avec déjà un WHERE
        $tp = $hasType ? ['types' => $types] : [];             // paramètres liés
        $tpt = $hasType ? ['types' => \Doctrine\DBAL\ArrayParameterType::STRING] : []; // typage du IN

        // --- Corpus global (filtré par type le cas échéant) ---
        // Sans filtre : total estimé (pg_class) — instantané sur 1,3 M lignes.
        // --- Métriques : depuis les VUES MATÉRIALISÉES (rafraîchies en cron) si
        // aucun filtre type n'est actif ; sinon calcul direct (filtré). Repli live
        // si la vue n'est pas encore peuplée. ---
        $mv = null;
        if (!$hasType) {
            try {
                $mv = $conn->executeQuery('SELECT * FROM dashboard_stats LIMIT 1')->fetchAssociative() ?: null;
            } catch (\Throwable) {
                $mv = null;
            }
        }

        if (null !== $mv) {
            $publications = (int) $mv['publications'];
            $freeFullArticles = (int) $mv['free_full'];
            $paywalled = (int) $mv['paywalled'];
            $embeddingTotal = (int) $mv['embedding_total'];
            $fulltextGrobid = (int) $mv['fulltext_grobid'];
            $fulltextRetryable = (int) $mv['fulltext_retryable'];
            $pdfConsultables = (int) $mv['pdf_consultables'];
            $answersValidated = (int) $mv['answers_validated'];
            $answersAi = (int) $mv['answers_ai'];
            $questionsAi = (int) $mv['questions_ai'];
            $questionsHuman = (int) $mv['questions_human'];
            $authorsCount = (int) $mv['authors'];
            $publishersCount = (int) $mv['publishers'];
            $journalsCount = (int) $mv['journals'];
        } else {
            $publications = $hasType
                ? (int) $conn->executeQuery('SELECT count(*) FROM publication'.$tWhere, $tp, $tpt)->fetchOne()
                : (int) $conn->executeQuery("SELECT reltuples::bigint FROM pg_class WHERE relname = 'publication'")->fetchOne();
            $freeFullArticles = (int) $conn->executeQuery("SELECT count(*) FROM publication WHERE oa_status NOT IN ('closed','unknown')".$tAnd, $tp, $tpt)->fetchOne();
            $paywalled = (int) $conn->executeQuery("SELECT count(*) FROM publication WHERE oa_status = 'closed'".$tAnd, $tp, $tpt)->fetchOne();
            $pdfConsultables = $hasType
                ? (int) $conn->executeQuery('SELECT count(DISTINCT pc.publication_id) FROM publication_chunk pc JOIN publication p ON p.id = pc.publication_id WHERE p.type IN (:types)', $tp, $tpt)->fetchOne()
                : (int) $conn->executeQuery('SELECT count(DISTINCT publication_id) FROM publication_chunk')->fetchOne();
            $embeddingTotal = (int) $conn->executeQuery('SELECT count(*) FROM publication WHERE embedding IS NOT NULL'.$tAnd, $tp, $tpt)->fetchOne();
            $fulltextRetryable = (int) $conn->executeQuery("SELECT count(*) FROM publication p WHERE p.fulltext_fetched_at IS NOT NULL AND p.oa_url IS NOT NULL AND p.oa_url <> '' AND NOT EXISTS (SELECT 1 FROM publication_chunk pc WHERE pc.publication_id = p.id)".$tAnd, $tp, $tpt)->fetchOne();
            $fulltextGrobid = (int) $conn->executeQuery("SELECT count(*) FROM publication WHERE fulltext_source = 'grobid_self'".$tAnd, $tp, $tpt)->fetchOne();
            $authorsCount = (int) $conn->executeQuery("SELECT reltuples::bigint FROM pg_class WHERE relname = 'author'")->fetchOne();
            $publishersCount = (int) $conn->executeQuery('SELECT count(*) FROM publisher')->fetchOne();
            $journalsCount = (int) $conn->executeQuery('SELECT count(*) FROM journal')->fetchOne();
            $answersValidated = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status = 'valide'")->fetchOne();
            $answersAi = (int) $conn->executeQuery("SELECT count(*) FROM answer WHERE validation_status = 'non_relu'")->fetchOne();
            $questionsAi = (int) $conn->executeQuery("SELECT count(*) FROM question WHERE origin = 'suggeree_ia'")->fetchOne();
            $questionsHuman = (int) $conn->executeQuery("SELECT count(*) FROM question WHERE origin = 'libre_utilisateur'")->fetchOne();
        }
        $fulltextVectorized = $pdfConsultables;
        $abstractOnly = max(0, $embeddingTotal - $fulltextVectorized);

        // Petites valeurs toujours calculées en direct (tables légères).
        $answers = $answersValidated + $answersAi;
        $questions = (int) $conn->executeQuery('SELECT count(*) FROM question')->fetchOne();
        $treeNodes = (int) $conn->executeQuery('SELECT count(*) FROM tree_node')->fetchOne();
        $topPublishers = $conn->executeQuery(
            'SELECT p.name, count(j.id) AS journals FROM publisher p JOIN journal j ON j.publisher_id = p.id
              GROUP BY p.id, p.name ORDER BY journals DESC, p.name LIMIT 10'
        )->fetchAllAssociative();
        $openAlexTotal = (int) $conn->executeQuery("SELECT COALESCE(SUM(value::bigint),0) FROM setting WHERE name LIKE 'openalex.total.%' AND value ~ '^[0-9]+$'")->fetchOne();

        // Articles RÉELLEMENT moissonnés par jour = nombre d'insertions ce jour-là
        // (DATE(created_at)). Série 30 jours sans trou.
        // Agréger created_at sur les 30 derniers jours peut coûter très cher lors d'une
        // grosse ingestion (des millions de lignes insérées le même jour). On borne la
        // requête (statement_timeout scopé par transaction) et on dégrade en série vide
        // plutôt que de faire tomber TOUT le dashboard en timeout PHP (30 s).
        $history = [];
        try {
            $conn->beginTransaction();
            $conn->executeStatement("SET LOCAL statement_timeout = '6s'");
            $history = $conn->executeQuery(
                "SELECT to_char(d.day, 'YYYY-MM-DD') AS day, COALESCE(c.n, 0) AS publications
                 FROM generate_series(CURRENT_DATE - INTERVAL '29 days', CURRENT_DATE, INTERVAL '1 day') AS d(day)
                 LEFT JOIN (
                     SELECT created_at::date AS day, count(*) AS n FROM publication
                     WHERE created_at >= CURRENT_DATE - INTERVAL '29 days' GROUP BY created_at::date
                 ) c ON c.day = d.day
                 ORDER BY d.day"
            )->fetchAllAssociative();
            $conn->commit();
        } catch (\Throwable) {
            try {
                $conn->rollBack();
            } catch (\Throwable) {
            }
            $history = [];
        }

        // Répartition par type : vue matérialisée (repli live si non peuplée).
        try {
            $typeBreakdown = $conn->executeQuery('SELECT type, n FROM dashboard_type_breakdown ORDER BY n DESC')->fetchAllAssociative();
            if ([] === $typeBreakdown) {
                throw new \RuntimeException('vide');
            }
        } catch (\Throwable) {
            $typeBreakdown = $conn->executeQuery("SELECT COALESCE(NULLIF(type, ''), '(inconnu)') AS type, count(*) AS n FROM publication GROUP BY 1 ORDER BY n DESC")->fetchAllAssociative();
        }

        // Par domaine racine : vue matérialisée (repli live si non peuplée).
        try {
            $roots = $conn->executeQuery('SELECT slug, label, publications FROM dashboard_domain_stats ORDER BY label')->fetchAllAssociative();
            if ([] === $roots) {
                throw new \RuntimeException('vide');
            }
        } catch (\Throwable) {
            $roots = [];
            foreach ($conn->executeQuery('SELECT slug, label FROM tree_node WHERE level = 0 ORDER BY label')->fetchAllAssociative() as $root) {
                $count = (int) $conn->executeQuery(
                    'WITH RECURSIVE sub AS (SELECT id FROM tree_node WHERE slug = :slug UNION SELECT e.child_id FROM tree_edge e JOIN sub ON e.parent_id = sub.id)
                     SELECT count(DISTINCT ps.publication_id) FROM placement_suggestion ps WHERE ps.tree_node_id IN (SELECT id FROM sub)',
                    ['slug' => $root['slug']],
                )->fetchOne();
                $roots[] = ['slug' => $root['slug'], 'label' => $root['label'], 'publications' => $count];
            }
        }

        // --- Base de données ---
        $dbVersion = (string) $conn->executeQuery('SHOW server_version')->fetchOne();
        $dbSize = (int) $conn->executeQuery('SELECT pg_database_size(current_database())')->fetchOne();
        // Aperçu volumétrie : estimations pg_class (1 requête) au lieu de 11 count(*)
        // exacts (dont authorship = plusieurs millions) — instantané.
        $wanted = ['publication', 'tree_node', 'tree_edge', 'question', 'answer', 'answer_revision', 'footnote', 'placement_suggestion', 'author', 'authorship', 'app_user'];
        $estimates = $conn->executeQuery(
            "SELECT relname, reltuples::bigint AS n FROM pg_class WHERE relkind = 'r' AND relname IN (:t)",
            ['t' => $wanted],
            ['t' => \Doctrine\DBAL\ArrayParameterType::STRING],
        )->fetchAllKeyValue();
        $tables = [];
        foreach ($wanted as $t) {
            $tables[$t] = (int) ($estimates[$t] ?? 0);
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
            'typeFilter' => $types,
            'families' => \App\Catalog\PublicationType::FAMILIES,
            'satelliteTypes' => \App\Catalog\PublicationType::SATELLITE,
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
