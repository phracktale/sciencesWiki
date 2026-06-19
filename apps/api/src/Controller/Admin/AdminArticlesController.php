<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liste paginée des articles moissonnés avec recherche (titre + auteur) et
 * colonnes auteur / date / revue / accès (libre ou payant). ROLE_ADMIN.
 */
final class AdminArticlesController
{
    private const PER_PAGE = 25;

    /** Statuts OA considérés comme « libre accès ». */
    private const OPEN = ['diamond', 'gold', 'green', 'hybrid', 'bronze'];
    private const OPEN_SQL = "('diamond','gold','green','hybrid','bronze')";

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/admin/publications', name: 'admin_publications', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $conn = $this->em->getConnection();
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * self::PER_PAGE;
        $like = '%'.$q.'%';

        // --- Filtres ---
        $conditions = [];
        $params = [];
        if ('' !== $q) {
            $conditions[] = '(p.title ILIKE :like OR EXISTS (SELECT 1 FROM authorship au JOIN author a ON a.id = au.author_id WHERE au.publication_id = p.id AND a.name ILIKE :like))';
            $params['like'] = $like;
        }
        $journalId = (int) $request->query->get('journal', '0');
        if ($journalId > 0) {
            $conditions[] = 'p.journal_id = :jid';
            $params['jid'] = $journalId;
        }
        switch ((string) $request->query->get('indexation', '')) {
            case 'fulltext': $conditions[] = 'EXISTS (SELECT 1 FROM publication_chunk pc WHERE pc.publication_id = p.id)'; break;
            case 'abstract': $conditions[] = 'NOT EXISTS (SELECT 1 FROM publication_chunk pc WHERE pc.publication_id = p.id)'; break;
        }
        switch ((string) $request->query->get('pdf', '')) {
            case 'vectorise': $conditions[] = 'EXISTS (SELECT 1 FROM publication_chunk pc WHERE pc.publication_id = p.id)'; break;
            case 'lien': $conditions[] = "p.oa_url IS NOT NULL AND p.oa_url <> '' AND NOT EXISTS (SELECT 1 FROM publication_chunk pc WHERE pc.publication_id = p.id)"; break;
            case 'aucun': $conditions[] = "(p.oa_url IS NULL OR p.oa_url = '') AND NOT EXISTS (SELECT 1 FROM publication_chunk pc WHERE pc.publication_id = p.id)"; break;
        }
        switch ((string) $request->query->get('access', '')) {
            case 'libre': $conditions[] = 'p.oa_status IN '.self::OPEN_SQL; break;
            case 'payant': $conditions[] = "p.oa_status = 'closed'"; break;
            case 'inconnu': $conditions[] = "p.oa_status NOT IN ".self::OPEN_SQL." AND p.oa_status <> 'closed'"; break;
        }
        $domain = trim((string) $request->query->get('domain', ''));
        if ('' !== $domain) {
            $conditions[] = 'EXISTS (WITH RECURSIVE sub AS (
                    SELECT id FROM tree_node WHERE slug = :domain
                    UNION SELECT e.child_id FROM tree_edge e JOIN sub ON e.parent_id = sub.id
                ) SELECT 1 FROM placement_suggestion ps WHERE ps.publication_id = p.id AND ps.tree_node_id IN (SELECT id FROM sub))';
            $params['domain'] = $domain;
        }
        $where = [] !== $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';

        // --- Tri par colonne + direction (en-têtes cliquables) ---
        $d = 'asc' === strtolower((string) $request->query->get('dir', '')) ? 'ASC' : 'DESC';
        $col = match ((string) $request->query->get('sort', '')) {
            'titre' => 'p.title',
            'revue' => 'COALESCE(j.name, p.venue)',
            'fragments' => '(SELECT count(*) FROM publication_chunk pc WHERE pc.publication_id = p.id)',
            default => 'p.publication_date',
        };
        $order = "$col $d NULLS LAST, p.id DESC";

        $total = (int) $conn->executeQuery("SELECT count(*) FROM publication p $where", $params)->fetchOne();

        $rows = $conn->executeQuery(
            "SELECT p.id, p.title, p.doi, p.venue, p.oa_status, p.oa_url, p.landing_page_url,
                    j.name AS journal_name,
                    to_char(p.publication_date, 'YYYY-MM-DD') AS date,
                    (SELECT count(*) FROM publication_chunk pc WHERE pc.publication_id = p.id) AS chunks,
                    (SELECT string_agg(a.name, ', ' ORDER BY au.position)
                       FROM authorship au JOIN author a ON a.id = au.author_id
                      WHERE au.publication_id = p.id) AS authors
             FROM publication p
             LEFT JOIN journal j ON j.id = p.journal_id
             $where
             ORDER BY $order
             LIMIT ".self::PER_PAGE.' OFFSET '.$offset,
            $params,
        )->fetchAllAssociative();

        $items = array_map(function (array $r): array {
            $status = (string) $r['oa_status'];
            $access = \in_array($status, self::OPEN, true) ? 'libre' : ('closed' === $status ? 'payant' : 'inconnu');
            $chunks = (int) $r['chunks'];
            $oaUrl = (string) ($r['oa_url'] ?? '');

            return [
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'authors' => $r['authors'] ?? '',
                'date' => $r['date'],
                'venue' => $r['journal_name'] ?? $r['venue'],
                'doi' => $r['doi'],
                'oaStatus' => $status,
                'access' => $access,
                // Lien : page canonique éditeur > PDF/OA > DOI.
                'url' => ($r['landing_page_url'] ?? null) ?: ($oaUrl ?: ($r['doi'] ? 'https://doi.org/'.$r['doi'] : null)),
                // Indexation RAG : texte intégral (fragments présents) ou résumé seul.
                'indexation' => $chunks > 0 ? 'fulltext' : 'abstract',
                'chunks' => $chunks,
                // PDF : vectorisé (fragments) > lien OA présent > aucun.
                'pdf' => $chunks > 0 ? 'vectorise' : ('' !== $oaUrl ? 'lien' : 'aucun'),
            ];
        }, $rows);

        return new JsonResponse([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'query' => $q,
        ]);
    }

    /** Autocomplete des revues (filtre de la liste d'articles). */
    #[Route('/api/admin/journals', name: 'admin_journals_search', methods: ['GET'])]
    public function journals(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse(['items' => []]);
        }
        $rows = $this->em->getConnection()->executeQuery(
            "SELECT j.id, j.name, p.name AS publisher,
                    (SELECT count(*) FROM publication pub WHERE pub.journal_id = j.id) AS articles
               FROM journal j LEFT JOIN publisher p ON p.id = j.publisher_id
              WHERE j.name ILIKE :like
              ORDER BY articles DESC, j.name LIMIT 15",
            ['like' => '%'.$q.'%'],
        )->fetchAllAssociative();

        return new JsonResponse(['items' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'publisher' => $r['publisher'],
            'articles' => (int) $r['articles'],
        ], $rows)]);
    }

    #[Route('/api/admin/publications/{id}', name: 'admin_publication_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $conn = $this->em->getConnection();
        $r = $conn->executeQuery(
            "SELECT p.id, p.title, p.abstract, p.doi, p.venue, p.oa_status, p.oa_url, p.language, p.type,
                    p.retraction_status,
                    to_char(p.publication_date, 'YYYY-MM-DD') AS date,
                    (SELECT string_agg(a.name, '|' ORDER BY au.position)
                       FROM authorship au JOIN author a ON a.id = au.author_id
                      WHERE au.publication_id = p.id) AS authors
             FROM publication p WHERE p.id = :id",
            ['id' => $id],
        )->fetchAssociative();

        if (false === $r) {
            return new JsonResponse(['error' => 'Publication introuvable.'], 404);
        }

        // Texte intégral reconstitué (fragments dans l'ordre) le cas échéant.
        $chunks = $conn->executeQuery(
            'SELECT content FROM publication_chunk WHERE publication_id = :id ORDER BY ord',
            ['id' => $id],
        )->fetchFirstColumn();

        $status = (string) $r['oa_status'];
        $access = \in_array($status, self::OPEN, true) ? 'libre' : ('closed' === $status ? 'payant' : 'inconnu');

        return new JsonResponse([
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'abstract' => $r['abstract'],
            'authors' => null !== $r['authors'] ? explode('|', (string) $r['authors']) : [],
            'date' => $r['date'],
            'venue' => $r['venue'],
            'doi' => $r['doi'],
            'language' => $r['language'],
            'type' => $r['type'],
            'oaStatus' => $status,
            'access' => $access,
            'oaUrl' => $r['oa_url'] ?: null,
            'doiUrl' => $r['doi'] ? 'https://doi.org/'.$r['doi'] : null,
            'retractionStatus' => $r['retraction_status'] ?? 'none',
            'fulltext' => [] !== $chunks ? implode("\n\n", array_map('strval', $chunks)) : null,
            'fulltextChunks' => \count($chunks),
        ]);
    }
}
