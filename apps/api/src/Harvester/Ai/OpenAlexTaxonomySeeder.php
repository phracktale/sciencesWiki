<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

use App\Entity\TreeEdge;
use App\Entity\TreeNode;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Amorce l'arbre des connaissances depuis la taxonomie OpenAlex (cf. spec §7) :
 * domaines → champs → sous-champs → topics. Chaque entité porte son parent
 * direct, ce qui donne un DAG propre. Embedding de chaque nœud (label +
 * description). Idempotent.
 */
final class OpenAlexTaxonomySeeder
{
    /** Endpoint OpenAlex par niveau. */
    private const ENDPOINTS = ['domains', 'fields', 'subfields', 'topics'];

    /** Clé du parent direct dans chaque enregistrement, par niveau. */
    private const PARENT_KEY = [1 => 'domain', 2 => 'field', 3 => 'subfield'];

    private readonly EmbeddingClient $embedder;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        EmbeddingClientFactory $embeddingFactory,
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
        private readonly \App\Harvester\OpenAlexThrottle $throttle,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail,
        #[Autowire(env: 'OPENALEX_BASE_URL')]
        private readonly string $baseUrl = 'https://api.openalex.org',
    ) {
        $this->embedder = $embeddingFactory->create();
    }

    /**
     * @param int $maxLevel         niveau maximal (0=domaines … 3=topics)
     * @param int $maxChildrenPerNode 0 = illimité ; sinon ne conserve que les N
     *                                enfants les plus importants (par works_count)
     *                                de chaque nœud, pour un arbre équilibré
     *
     * @return array{nodes:int,edges:int}
     */
    public function seed(int $maxLevel, int $maxChildrenPerNode = 0): array
    {
        $maxLevel = min($maxLevel, \count(self::ENDPOINTS) - 1);

        // 1) Collecte des enregistrements (qid, label, description, level, parentQid, worksCount).
        /** @var list<array{qid:string,label:string,description:?string,level:int,parentQid:?string,worksCount:int}> $records */
        $records = [];
        for ($level = 0; $level <= $maxLevel; ++$level) {
            foreach ($this->fetchAll(self::ENDPOINTS[$level]) as $raw) {
                $records[] = $this->parse($raw, $level);
            }
        }

        // 1b) Élagage top-down : ne garder que les N enfants les plus importants
        // par nœud (descendre dans une branche n'a de sens que si le parent est gardé).
        if ($maxChildrenPerNode > 0) {
            $records = $this->prune($records, $maxChildrenPerNode);
        }

        // 2) Nœuds.
        /** @var array<string,TreeNode> $byQid */
        $byQid = [];
        $usedSlugs = $this->existingSlugs();
        foreach ($records as $rec) {
            $byQid[$rec['qid']] = $this->upsertNode($rec, $usedSlugs);
        }
        $this->em->flush();

        // 3) Arêtes vers le parent direct.
        $existingEdges = $this->existingEdgeKeys();
        $edgeCount = 0;
        foreach ($records as $rec) {
            if (null === $rec['parentQid']) {
                continue;
            }
            $child = $byQid[$rec['qid']] ?? null;
            $parent = $byQid[$rec['parentQid']] ?? null;
            if (null === $child || null === $parent) {
                continue;
            }
            $key = $parent->getId().':'.$child->getId();
            if (isset($existingEdges[$key])) {
                continue;
            }
            $this->em->persist(new TreeEdge($parent, $child, true));
            $existingEdges[$key] = true;
            ++$edgeCount;
        }
        $this->em->flush();

        return ['nodes' => \count($records), 'edges' => $edgeCount];
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return array{qid:string,label:string,description:?string,level:int,parentQid:?string}
     */
    private function parse(array $raw, int $level): array
    {
        $parentQid = null;
        if (isset(self::PARENT_KEY[$level])) {
            $parent = $raw[self::PARENT_KEY[$level]] ?? null;
            if (\is_array($parent) && isset($parent['id'])) {
                $parentQid = self::qualifiedId((string) $parent['id']);
            }
        }

        return [
            'qid' => self::qualifiedId((string) ($raw['id'] ?? '')),
            'label' => (string) ($raw['display_name'] ?? ''),
            'description' => isset($raw['description']) ? (string) $raw['description'] : null,
            'level' => $level,
            'parentQid' => $parentQid,
            'worksCount' => (int) ($raw['works_count'] ?? 0),
        ];
    }

    /**
     * Élague l'arbre top-down : conserve toutes les racines (niveau 0) puis, pour
     * chaque nœud gardé, ses N enfants les plus importants (works_count décroissant).
     *
     * @param list<array{qid:string,label:string,description:?string,level:int,parentQid:?string,worksCount:int}> $records
     *
     * @return list<array{qid:string,label:string,description:?string,level:int,parentQid:?string,worksCount:int}>
     */
    private function prune(array $records, int $maxChildren): array
    {
        /** @var array<string,list<array<string,mixed>>> $childrenOf */
        $childrenOf = [];
        $roots = [];
        foreach ($records as $rec) {
            if (null === $rec['parentQid']) {
                $roots[] = $rec;
            } else {
                $childrenOf[$rec['parentQid']][] = $rec;
            }
        }

        /** @var list<array<string,mixed>> $kept */
        $kept = $roots;
        $frontier = $roots;
        while ([] !== $frontier) {
            $next = [];
            foreach ($frontier as $parent) {
                $children = $childrenOf[$parent['qid']] ?? [];
                usort($children, static fn (array $a, array $b): int => $b['worksCount'] <=> $a['worksCount']);
                foreach (\array_slice($children, 0, $maxChildren) as $child) {
                    $kept[] = $child;
                    $next[] = $child;
                }
            }
            $frontier = $next;
        }

        return $kept;
    }

    /**
     * @param array{qid:string,label:string,description:?string,level:int,parentQid:?string} $rec
     * @param array<string,true>                                                              $usedSlugs
     */
    private function upsertNode(array $rec, array &$usedSlugs): TreeNode
    {
        $node = $this->nodes->findOneByConceptId($rec['qid']);
        if (null === $node) {
            $node = new TreeNode($this->uniqueSlug($rec['label'], $rec['qid'], $usedSlugs), $rec['label']);
            $node->setOpenalexConceptId($rec['qid']);
            $this->em->persist($node);
        } else {
            $node->setLabel($rec['label']);
        }

        $node->setDescription($rec['description'])->setLevel($rec['level']);
        $node->setEmbedding($this->embedder->embed(trim($rec['label'].'. '.($rec['description'] ?? ''))));

        return $node;
    }

    /**
     * @return iterable<array<string,mixed>>
     */
    private function fetchAll(string $endpoint): iterable
    {
        $cursor = '*';
        while (null !== $cursor) {
            $data = $this->requestJson($endpoint, $cursor);

            foreach (($data['results'] ?? []) as $row) {
                if (\is_array($row)) {
                    yield $row;
                }
            }

            $next = $data['meta']['next_cursor'] ?? null;
            $cursor = (\is_string($next) && '' !== $next && [] !== ($data['results'] ?? [])) ? $next : null;
        }
    }

    /**
     * Requête une page, avec back-off sur 429/503 (politesse OpenAlex).
     *
     * @return array<string,mixed>
     */
    private function requestJson(string $endpoint, string $cursor): array
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->throttle->tick();
            $response = $this->httpClient->request('GET', $this->baseUrl.'/'.$endpoint, [
                'query' => ['per-page' => 200, 'cursor' => $cursor, 'mailto' => $this->contactEmail],
                'headers' => ['User-Agent' => $this->userAgent()],
            ]);

            $status = $response->getStatusCode();
            if (429 === $status || 503 === $status) {
                sleep(2 * $attempt);
                continue;
            }

            return $response->toArray();
        }

        throw new \RuntimeException(\sprintf('OpenAlex %s : trop de réponses 429/503.', $endpoint));
    }

    /**
     * @return array<string,true>
     */
    private function existingSlugs(): array
    {
        $slugs = [];
        foreach ($this->em->getConnection()->executeQuery('SELECT slug FROM tree_node')->fetchFirstColumn() as $slug) {
            $slugs[(string) $slug] = true;
        }

        return $slugs;
    }

    /**
     * @return array<string,true>
     */
    private function existingEdgeKeys(): array
    {
        $keys = [];
        foreach ($this->em->getConnection()->executeQuery('SELECT parent_id, child_id FROM tree_edge')->fetchAllAssociative() as $row) {
            $keys[$row['parent_id'].':'.$row['child_id']] = true;
        }

        return $keys;
    }

    /**
     * @param array<string,true> $usedSlugs
     */
    private function uniqueSlug(string $label, string $qid, array &$usedSlugs): string
    {
        $base = self::slugify($label);
        if ('' === $base) {
            $base = self::slugify(str_replace('/', '-', $qid));
        }

        $slug = $base;
        if (isset($usedSlugs[$slug])) {
            $slug = $base.'-'.self::slugify(str_replace('/', '-', $qid));
        }
        $usedSlugs[$slug] = true;

        return mb_substr($slug, 0, 255);
    }

    private static function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $value);

        return trim($value, '-');
    }

    /** « https://openalex.org/fields/22 » → « fields/22 » (unique entre niveaux). */
    private static function qualifiedId(string $url): string
    {
        if (preg_match('#openalex\.org/(.+)$#', trim($url), $m)) {
            return $m[1];
        }

        return trim($url);
    }

    private function userAgent(): string
    {
        return \sprintf('SciencesWiki/0.1 (+https://scienceswiki.org; mailto:%s)', $this->contactEmail);
    }
}
