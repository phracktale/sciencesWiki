<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ArticleRevisionRepository;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office : consultation des articles wiki + historique des versions
 * (auteurs intervenus, diffs). Réservé à ROLE_ADMIN (access_control ^/api/admin).
 */
final class AdminWikiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TreeNodeRepository $nodes,
        private readonly ArticleRevisionRepository $revisions,
    ) {
    }

    /** Liste des articles rédigés (filtre texte + statut). */
    #[Route('/api/admin/wiki-articles', name: 'admin_wiki_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $conn = $this->em->getConnection();
        $q = trim((string) $request->query->get('q', ''));
        $status = trim((string) $request->query->get('status', ''));

        $where = ["t.article_md IS NOT NULL AND t.article_md <> ''"];
        $params = [];
        if ('' !== $q) {
            $where[] = 't.label ILIKE :like';
            $params['like'] = '%'.$q.'%';
        }
        if (\in_array($status, ['valide', 'non_relu'], true)) {
            $where[] = 't.article_status = :st';
            $params['st'] = $status;
        }
        $sql = 'SELECT t.id, t.slug, t.label, t.level, t.article_status,
                       length(t.article_md) AS length,
                       to_char(t.article_generated_at, \'YYYY-MM-DD HH24:MI\') AS generated_at,
                       to_char(t.article_reviewed_at, \'YYYY-MM-DD HH24:MI\') AS reviewed_at,
                       (SELECT count(*) FROM node_article_revision r WHERE r.tree_node_id = t.id) AS revisions,
                       (SELECT r.author_label FROM node_article_revision r WHERE r.tree_node_id = t.id ORDER BY r.created_at DESC LIMIT 1) AS last_author,
                       (SELECT r.author_type FROM node_article_revision r WHERE r.tree_node_id = t.id ORDER BY r.created_at DESC LIMIT 1) AS last_author_type
                FROM tree_node t
                WHERE '.implode(' AND ', $where).'
                ORDER BY t.article_reviewed_at DESC NULLS LAST, t.article_generated_at DESC NULLS LAST
                LIMIT 200';

        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();
        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'label' => $r['label'],
            'level' => (int) $r['level'],
            'status' => $r['article_status'],
            'length' => (int) $r['length'],
            'generatedAt' => $r['generated_at'],
            'reviewedAt' => $r['reviewed_at'],
            'revisions' => (int) $r['revisions'],
            'lastAuthor' => $r['last_author'],
            'lastAuthorType' => $r['last_author_type'],
        ], $rows);

        return new JsonResponse(['items' => $items, 'query' => $q, 'status' => $status]);
    }

    /** Détail d'un article + toutes ses révisions (avec contenu, pour le diff). */
    #[Route('/api/admin/wiki-articles/{id}', name: 'admin_wiki_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $node = $this->nodes->find($id);
        if (null === $node) {
            return new JsonResponse(['error' => 'Rubrique introuvable.'], 404);
        }

        $revisions = array_map(static fn (\App\Entity\ArticleRevision $r): array => [
            'id' => $r->getId(),
            'authorType' => $r->getAuthorType(),
            'author' => $r->getAuthorLabel(),
            'summary' => $r->getChangeSummary(),
            'createdAt' => $r->getCreatedAt()->format('Y-m-d H:i'),
            'length' => mb_strlen($r->getContentMd()),
            'content' => $r->getContentMd(),
        ], $this->revisions->forNode($node));

        return new JsonResponse([
            'id' => $node->getId(),
            'slug' => $node->getSlug(),
            'label' => $node->getLabel(),
            'status' => $node->getArticleStatus(),
            'model' => $node->getArticleModel(),
            'url' => '/fr/'.$node->getSlug(),
            'revisions' => $revisions,
        ]);
    }
}
