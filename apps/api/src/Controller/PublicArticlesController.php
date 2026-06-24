<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recherche et consultation publiques des articles d'un sous-domaine (le nœud +
 * ses descendants), façon explorateur OpenAlex : liste paginée + fiche détaillée
 * richement formatée. Recherche plein-texte avec stemming activable.
 */
final class PublicArticlesController
{
    private const PER_PAGE = 20;

    /** Statuts OpenAlex en accès ouvert. */
    private const OPEN = ['diamond', 'gold', 'green', 'hybrid', 'bronze'];

    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly EntityManagerInterface $em,
        private readonly \App\Ai\Llm\LlmClient $llm,
        private readonly \App\Harvester\Ai\EmbeddingClientFactory $embeddings,
    ) {
    }

    /** Traduit (et met en cache) le résumé en français pour un article non francophone. */
    #[Route('/api/articles/{id}/translate', name: 'api_article_translate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function translate(int $id): JsonResponse
    {
        $pub = $this->publications->find($id);
        if (null === $pub) {
            return new JsonResponse(['error' => 'Article introuvable.'], 404);
        }
        if (null !== $pub->getAbstractFr() && '' !== $pub->getAbstractFr()) {
            return new JsonResponse(['abstractFr' => $pub->getAbstractFr(), 'cached' => true]);
        }
        $abstract = (string) $pub->getAbstract();
        if ('' === trim($abstract)) {
            return new JsonResponse(['error' => 'Pas de résumé à traduire.'], 422);
        }

        set_time_limit(0); // la génération LLM peut dépasser la limite PHP par défaut
        try {
            $completion = $this->llm->complete([
                \App\Ai\Llm\LlmMessage::system('Tu es un traducteur scientifique. Traduis fidèlement en français, sans rien ajouter ni commenter, en conservant la terminologie. Réponds UNIQUEMENT la traduction.'),
                \App\Ai\Llm\LlmMessage::user($abstract),
            ], ['temperature' => 0.1, 'max_tokens' => 1500]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Échec de la traduction : '.$e->getMessage()], 502);
        }

        $fr = trim($completion->content);
        if ('' === $fr) {
            return new JsonResponse(['error' => 'Traduction vide.'], 502);
        }
        $pub->setAbstractFr($fr);
        $this->em->flush();

        return new JsonResponse(['abstractFr' => $fr, 'cached' => false]);
    }

    #[Route('/api/nodes/{slug}/articles', name: 'api_node_articles', methods: ['GET'])]
    public function list(string $slug, Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', '1'));
        $stemming = '0' !== (string) $request->query->get('stemming', '1');
        $semantic = '1' === (string) $request->query->get('semantic', '0');
        $sort = (string) $request->query->get('sort', '');
        $dir = (string) $request->query->get('dir', 'asc');
        $types = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $request->query->all('types'),
        )));

        // Recherche en langage naturel : récupération hybride (sémantique + lexicale)
        // classée par pertinence. Repli silencieux sur le plein-texte si l'embedding
        // échoue, ou si la requête est vide (rien à vectoriser), ou si un tri colonne
        // est demandé (incompatible avec un classement par pertinence).
        if ($semantic && '' !== $q && '' === $sort) {
            try {
                $embedding = $this->embeddings->create()->embed($q);
                $res = $this->publications->searchInSubtreeHybrid($slug, $embedding, $q, $page, self::PER_PAGE, $types);
            } catch (\Throwable) {
                $semantic = false;
                $res = $this->publications->searchInSubtree($slug, $q, $stemming, $page, self::PER_PAGE, $sort, $dir, $types);
            }
        } else {
            $semantic = false;
            $res = $this->publications->searchInSubtree($slug, $q, $stemming, $page, self::PER_PAGE, $sort, $dir, $types);
        }
        $appliedTypes = \App\Catalog\PublicationType::searchTypes($types);

        $items = array_map(function (array $r): array {
            $status = (string) $r['oa_status'];

            return [
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'authors' => $r['authors'] ?? '',
                'year' => $r['year'],
                'venue' => $r['journal_name'] ?? $r['venue'],
                'openAccess' => \in_array($status, self::OPEN, true),
                'oaStatus' => $status,
                'fulltext' => (int) $r['chunks'] > 0,
            ];
        }, $res['items']);

        return new JsonResponse([
            'items' => $items,
            'total' => $res['total'],
            'page' => $page,
            'pages' => (int) ceil($res['total'] / self::PER_PAGE),
            'query' => $q,
            'stemming' => $stemming,
            'semantic' => $semantic,
            'sort' => $sort,
            'dir' => $dir,
            // Filtre de type (front) : familles proposables + types réellement appliqués.
            'families' => \App\Catalog\PublicationType::selectableFamilies(),
            'primaryTypes' => \App\Catalog\PublicationType::PRIMARY,
            'appliedTypes' => $appliedTypes,
        ]);
    }

    #[Route('/api/articles/{id}', name: 'api_article_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $conn = $this->em->getConnection();
        $r = $conn->executeQuery(
            "SELECT p.id, p.title, p.abstract, p.abstract_fr, p.doi, p.venue, p.oa_status, p.oa_url, p.landing_page_url,
                    p.language, p.type, p.retraction_status,
                    to_char(p.publication_date, 'YYYY-MM-DD') AS date,
                    to_char(p.publication_date, 'YYYY') AS year,
                    j.name AS journal_name, j.issn_l, pub.name AS publisher_name, pub.homepage_url AS publisher_url,
                    (SELECT string_agg(a.name, '|' ORDER BY au.position)
                       FROM authorship au JOIN author a ON a.id = au.author_id
                      WHERE au.publication_id = p.id) AS authors,
                    (SELECT count(*) FROM publication_chunk pc WHERE pc.publication_id = p.id) AS chunks
             FROM publication p
             LEFT JOIN journal j ON j.id = p.journal_id
             LEFT JOIN publisher pub ON pub.id = j.publisher_id
             WHERE p.id = :id",
            ['id' => $id],
        )->fetchAssociative();

        if (false === $r) {
            return new JsonResponse(['error' => 'Article introuvable.'], 404);
        }

        $status = (string) $r['oa_status'];
        // Hiérarchie thématique : meilleur placement → fil d'Ariane (domaine→…→rubrique).
        $hierarchy = $this->hierarchy($id);

        return new JsonResponse([
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'abstract' => $r['abstract'],
            'abstractFr' => $r['abstract_fr'] ?: null,
            'authors' => null !== $r['authors'] ? explode('|', (string) $r['authors']) : [],
            'year' => $r['year'],
            'date' => $r['date'],
            'type' => $r['type'],
            'language' => $r['language'],
            'venue' => $r['journal_name'] ?? $r['venue'],
            'issnL' => $r['issn_l'],
            'publisher' => $r['publisher_name'],
            'publisherUrl' => $r['publisher_url'],
            'doi' => $r['doi'],
            'doiUrl' => $r['doi'] ? 'https://doi.org/'.$r['doi'] : null,
            'oaStatus' => $status,
            'openAccess' => \in_array($status, self::OPEN, true),
            'oaUrl' => $r['oa_url'] ?: null,
            'landingPageUrl' => $r['landing_page_url'] ?: null,
            'retractionStatus' => $r['retraction_status'] ?? 'none',
            'fulltextAvailable' => (int) $r['chunks'] > 0,
            'hierarchy' => $hierarchy,
        ]);
    }

    /**
     * Fil d'Ariane thématique (domaine → … → rubrique) du meilleur placement.
     *
     * @return list<array{slug:string,label:string,level:int}>
     */
    private function hierarchy(int $publicationId): array
    {
        $conn = $this->em->getConnection();
        $nodeId = $conn->executeQuery(
            'SELECT tree_node_id FROM placement_suggestion WHERE publication_id = :id ORDER BY score DESC NULLS LAST LIMIT 1',
            ['id' => $publicationId],
        )->fetchOne();
        if (false === $nodeId) {
            return [];
        }

        $rows = $conn->executeQuery(
            'WITH RECURSIVE up AS (
                SELECT n.id, n.slug, n.label, n.level, 0 AS depth FROM tree_node n WHERE n.id = :nid
                UNION ALL
                SELECT n.id, n.slug, n.label, n.level, up.depth + 1
                  FROM tree_edge e JOIN up ON e.child_id = up.id JOIN tree_node n ON n.id = e.parent_id
            ) SELECT slug, label, level FROM up ORDER BY level ASC',
            ['nid' => (int) $nodeId],
        )->fetchAllAssociative();

        return array_map(static fn (array $n): array => [
            'slug' => $n['slug'],
            'label' => $n['label'],
            'level' => (int) $n['level'],
        ], $rows);
    }
}
