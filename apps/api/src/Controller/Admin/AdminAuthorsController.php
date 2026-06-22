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

        // Sans recherche : total estimé (pg_class) — instantané sur 3,2 M auteurs.
        $total = '' === $q
            ? (int) $conn->executeQuery("SELECT reltuples::bigint FROM pg_class WHERE relname = 'author'")->fetchOne()
            : (int) $conn->executeQuery("SELECT count(*) FROM author a $where", $params)->fetchOne();

        // Tri (liste blanche) par colonne + direction (en-têtes cliquables).
        $sort = (string) $request->query->get('sort', '');
        $d = 'asc' === strtolower((string) $request->query->get('dir', '')) ? 'ASC' : 'DESC';
        $col = match ($sort) {
            'retractions' => 'retractions',
            'eoc' => 'eoc',
            'nom' => 'a.name',
            default => 'a.publication_count',
        };
        $order = 'a.name' === $col ? "a.name $d" : "$col $d, a.name ASC";

        $rows = $conn->executeQuery(
            "SELECT a.id, a.name, a.orcid, a.affiliation,
                    a.publication_count AS publications,
                    (SELECT count(*) FROM authorship au JOIN publication p ON p.id = au.publication_id
                       WHERE au.author_id = a.id AND p.retraction_status = 'retracted') AS retractions,
                    (SELECT count(*) FROM authorship au JOIN publication p ON p.id = au.publication_id
                       WHERE au.author_id = a.id AND p.retraction_status = 'concern') AS eoc
             FROM author a
             $where
             ORDER BY $order
             LIMIT ".self::PER_PAGE.' OFFSET '.$offset,
            $params,
        )->fetchAllAssociative();

        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'orcid' => $r['orcid'],
            'affiliation' => $r['affiliation'],
            'publications' => (int) $r['publications'],
            'retractions' => (int) $r['retractions'],
            'eoc' => (int) $r['eoc'],
        ], $rows);

        return new JsonResponse([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'query' => $q,
            'sort' => $sort,
        ]);
    }
}
