<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TreeEdge;
use App\Entity\TreeNode;
use App\Harvester\Ai\EmbeddingClientFactory;
use App\Harvester\OpenAlexThrottle;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Aide à l'édition de la taxonomie via OpenAlex (ROLE_ADMIN) :
 *  - autocomplete sur les concepts (recherche locale : l'arbre reflète OpenAlex) ;
 *  - greffe des sous-rubriques OpenAlex manquantes sous un nœud (amélioration
 *    incrémentale de l'arbre, idempotente).
 */
final class AdminOpenAlexController
{
    /** Pour chaque niveau : endpoint OpenAlex des enfants + clé de filtre parent. */
    private const CHILDREN = [
        0 => ['fields', 'domain.id'],
        1 => ['subfields', 'field.id'],
        2 => ['topics', 'subfield.id'],
    ];

    private readonly EmbeddingClientFactory $embeddingFactory;

    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly OpenAlexThrottle $throttle,
        private readonly \Symfony\Component\Messenger\MessageBusInterface $bus,
        EmbeddingClientFactory $embeddingFactory,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail,
        #[Autowire(env: 'OPENALEX_BASE_URL')]
        private readonly string $baseUrl = 'https://api.openalex.org',
        #[Autowire(env: 'OPENALEX_API_KEY')]
        private readonly string $apiKey = '',
    ) {
        $this->embeddingFactory = $embeddingFactory;
    }

    #[\Symfony\Component\Routing\Attribute\Route('/api/admin/openalex/search', name: 'admin_openalex_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse(['items' => []]);
        }

        $rows = $this->em->getConnection()->executeQuery(
            "SELECT id, slug, label, level, openalex_concept_id
             FROM tree_node WHERE label ILIKE :q ORDER BY level, label LIMIT 15",
            ['q' => '%'.$q.'%'],
        )->fetchAllAssociative();

        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'label' => $r['label'],
            'level' => (int) $r['level'],
            'openalexId' => $r['openalex_concept_id'],
        ], $rows);

        return new JsonResponse(['items' => $items]);
    }

    #[\Symfony\Component\Routing\Attribute\Route('/api/admin/nodes/{id}/graft-children', name: 'admin_node_graft', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function graftChildren(int $id): JsonResponse
    {
        $node = $this->nodes->find($id);
        if (null === $node) {
            return new JsonResponse(['error' => 'Rubrique introuvable.'], 404);
        }
        $concept = $node->getOpenalexConceptId();
        if (null === $concept || !isset(self::CHILDREN[$node->getLevel()])) {
            return new JsonResponse(['error' => 'Cette rubrique n\'est pas un concept OpenAlex avec des enfants greffables.'], 422);
        }

        [$endpoint, $filterKey] = self::CHILDREN[$node->getLevel()];
        $embedder = $this->embeddingFactory->create();
        $existing = $this->existingConceptIds();
        $added = 0;
        $cursor = '*';

        while (null !== $cursor) {
            $this->throttle->tick();
            $data = $this->httpClient->request('GET', $this->baseUrl.'/'.$endpoint, [
                'query' => array_filter([
                    'filter' => $filterKey.':https://openalex.org/'.$concept,
                    'per-page' => 200,
                    'cursor' => $cursor,
                    'mailto' => $this->contactEmail,
                    'api_key' => '' !== $this->apiKey ? $this->apiKey : null,
                ], static fn ($v): bool => null !== $v),
                'timeout' => 30,
            ])->toArray(false);

            foreach (($data['results'] ?? []) as $row) {
                $qid = $this->qualifiedId((string) ($row['id'] ?? ''));
                $label = (string) ($row['display_name'] ?? '');
                if ('' === $qid || '' === $label || isset($existing[$qid])) {
                    continue;
                }
                $child = new TreeNode($this->uniqueSlug($label), $label);
                $child->setOpenalexConceptId($qid)->setLevel($node->getLevel() + 1)
                    ->setDescription(isset($row['description']) ? (string) $row['description'] : null)
                    ->setEmbedding($embedder->embed($label));
                $this->em->persist($child);
                $this->em->persist(new TreeEdge($node, $child, true));
                $existing[$qid] = true;
                ++$added;
            }

            $next = $data['meta']['next_cursor'] ?? null;
            $cursor = (\is_string($next) && '' !== $next && [] !== ($data['results'] ?? [])) ? $next : null;
        }
        $this->em->flush();

        return new JsonResponse(['added' => $added, 'message' => \sprintf('%d sous-rubrique(s) ajoutée(s).', $added)]);
    }

    #[\Symfony\Component\Routing\Attribute\Route('/api/admin/nodes/{id}/harvest', name: 'admin_node_harvest', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function harvest(int $id): JsonResponse
    {
        $node = $this->nodes->find($id);
        if (null === $node) {
            return new JsonResponse(['error' => 'Rubrique introuvable.'], 404);
        }
        if (null === $node->getOpenalexConceptId()) {
            return new JsonResponse(['error' => 'Cette rubrique n\'est pas mappée à un concept OpenAlex.'], 422);
        }

        $this->bus->dispatch(new \App\Harvester\Message\HarvestRubric($id));

        return new JsonResponse(['message' => 'Moisson de la rubrique lancée en arrière-plan (le worker traite la file).']);
    }

    #[\Symfony\Component\Routing\Attribute\Route('/api/admin/nodes/{id}/harvest/cancel', name: 'admin_node_harvest_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancelHarvest(int $id): JsonResponse
    {
        // Annule les moissons EN ATTENTE pour ce nœud (messages non encore pris par
        // un worker). Le message HarvestRubric ne contient que le nodeId (sérialisé
        // « i:<id>; »), borné au type de message pour éviter les faux positifs.
        // Une moisson déjà EN COURS n'est pas interrompue (bornée à 500, elle finit).
        try {
            $cancelled = $this->em->getConnection()->executeStatement(
                "DELETE FROM messenger_messages
                 WHERE delivered_at IS NULL AND body LIKE '%HarvestRubric%' AND body LIKE :idpat",
                ['idpat' => '%i:'.$id.';%'],
            );
        } catch (\Throwable) {
            $cancelled = 0;
        }

        return new JsonResponse([
            'cancelled' => $cancelled,
            'message' => $cancelled > 0
                ? \sprintf('%d moisson(s) en attente annulée(s).', $cancelled)
                : 'Aucune moisson en attente à annuler (une moisson déjà en cours se termine d\'elle-même).',
        ]);
    }

    /** @return array<string,true> */
    private function existingConceptIds(): array
    {
        $ids = [];
        foreach ($this->em->getConnection()->executeQuery('SELECT openalex_concept_id FROM tree_node WHERE openalex_concept_id IS NOT NULL')->fetchFirstColumn() as $cid) {
            $ids[(string) $cid] = true;
        }

        return $ids;
    }

    private function uniqueSlug(string $label): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($label)), '-') ?: 'rubrique';
        $slug = $base;
        $i = 2;
        while (null !== $this->nodes->findOneBy(['slug' => $slug])) {
            $slug = $base.'-'.$i++;
        }

        return mb_substr($slug, 0, 255);
    }

    private static function qualifiedId(string $url): string
    {
        return preg_match('#openalex\.org/(.+)$#', trim($url), $m) ? $m[1] : trim($url);
    }
}
