<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liste paginée des auteurs des publications moissonnées (nom, ORCID, affiliation,
 * nombre de publications), avec recherche. ROLE_ADMIN.
 */
final class AdminAuthorsController
{
    private const PER_PAGE = 30;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/admin/authors', name: 'admin_authors', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $conn = $this->em->getConnection();
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * self::PER_PAGE;

        $where = '';
        $params = [];
        if ('' !== $q) {
            $where = 'WHERE a.name ILIKE :like OR a.orcid ILIKE :like';
            $params['like'] = '%'.$q.'%';
        }

        $total = (int) $conn->executeQuery("SELECT count(*) FROM author a $where", $params)->fetchOne();

        $rows = $conn->executeQuery(
            "SELECT a.id, a.name, a.orcid, a.affiliation,
                    (SELECT count(*) FROM authorship au WHERE au.author_id = a.id) AS publications
             FROM author a
             $where
             ORDER BY publications DESC, a.name ASC
             LIMIT ".self::PER_PAGE.' OFFSET '.$offset,
            $params,
        )->fetchAllAssociative();

        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'orcid' => $r['orcid'],
            'affiliation' => $r['affiliation'],
            'publications' => (int) $r['publications'],
        ], $rows);

        return new JsonResponse([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'query' => $q,
        ]);
    }
}
