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

        $where = '';
        $params = [];
        if ('' !== $q) {
            $where = 'WHERE (p.title ILIKE :like OR EXISTS (SELECT 1 FROM authorship au JOIN author a ON a.id = au.author_id WHERE au.publication_id = p.id AND a.name ILIKE :like))';
            $params['like'] = $like;
        }

        $total = (int) $conn->executeQuery("SELECT count(*) FROM publication p $where", $params)->fetchOne();

        $rows = $conn->executeQuery(
            "SELECT p.id, p.title, p.doi, p.venue, p.oa_status, p.oa_url,
                    to_char(p.publication_date, 'YYYY-MM-DD') AS date,
                    (SELECT count(*) FROM publication_chunk pc WHERE pc.publication_id = p.id) AS chunks,
                    (SELECT string_agg(a.name, ', ' ORDER BY au.position)
                       FROM authorship au JOIN author a ON a.id = au.author_id
                      WHERE au.publication_id = p.id) AS authors
             FROM publication p
             $where
             ORDER BY p.publication_date DESC NULLS LAST, p.id DESC
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
                'venue' => $r['venue'],
                'doi' => $r['doi'],
                'oaStatus' => $status,
                'access' => $access,
                'url' => $oaUrl ?: ($r['doi'] ? 'https://doi.org/'.$r['doi'] : null),
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
