<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recherche publique dans les articles encyclopédiques (nœuds de l'arbre).
 */
final class PublicWikiController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/wiki/search', name: 'api_wiki_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $conn = $this->em->getConnection();
        $q = trim((string) $request->query->get('q', ''));

        // Extrait lisible : on retire le balisage Markdown le plus courant.
        $snippet = "left(regexp_replace(regexp_replace(coalesce(article_md, description, ''), '[#>*_`\\[\\]]', '', 'g'), '\\s+', ' ', 'g'), 240)";

        if ('' === $q) {
            // Sans requête : articles en vedette (domaines d'abord).
            $rows = $conn->executeQuery(
                "SELECT slug, label, level, article_status, (article_md IS NOT NULL) AS has_article,
                        $snippet AS snippet
                   FROM tree_node
                  WHERE article_md IS NOT NULL
                  ORDER BY level ASC, label ASC
                  LIMIT 40"
            )->fetchAllAssociative();
        } else {
            $rows = $conn->executeQuery(
                "SELECT slug, label, level, article_status, (article_md IS NOT NULL) AS has_article,
                        $snippet AS snippet
                   FROM tree_node
                  WHERE label ILIKE :like OR description ILIKE :like OR article_md ILIKE :like
                  ORDER BY (label ILIKE :like) DESC, (article_md IS NOT NULL) DESC, level ASC, label ASC
                  LIMIT 40",
                ['like' => '%'.$q.'%'],
            )->fetchAllAssociative();
        }

        $items = array_map(static fn (array $r): array => [
            'slug' => $r['slug'],
            'label' => $r['label'],
            'level' => (int) $r['level'],
            'status' => $r['article_status'],
            'hasArticle' => (bool) $r['has_article'],
            'snippet' => trim((string) $r['snippet']),
        ], $rows);

        return new JsonResponse(['items' => $items, 'query' => $q]);
    }
}
