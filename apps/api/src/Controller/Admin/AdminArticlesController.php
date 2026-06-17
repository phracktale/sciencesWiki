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

            return [
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'authors' => $r['authors'] ?? '',
                'date' => $r['date'],
                'venue' => $r['venue'],
                'doi' => $r['doi'],
                'oaStatus' => $status,
                'access' => $access,
                'url' => $r['oa_url'] ?: ($r['doi'] ? 'https://doi.org/'.$r['doi'] : null),
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
}
