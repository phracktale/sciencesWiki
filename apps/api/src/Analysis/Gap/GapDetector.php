<?php

declare(strict_types=1);

namespace App\Analysis\Gap;

use App\Entity\Claim;
use App\Entity\ResearchGap;
use App\Entity\TreeNode;
use App\Enum\ClaimMethod;
use App\Enum\GapType;
use App\Repository\ClaimRepository;
use App\Repository\ResearchGapRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Détecte les pistes inexplorées d'un nœud par trois voies
 * (cf. docs/spec-controverses-lacunes.md §6.2–§6.4) :
 *  - chaînon manquant (Swanson ABC) — appliqué au GRAPHE DES CLAIMS (relations
 *    réellement étudiées) faute de concepts OpenAlex stockés sur les publications ;
 *  - cellule creuse (résultat bien étudié, mais jamais pour une population ou par
 *    une méthode de haut niveau de preuve) ;
 *  - lacune auto-déclarée (pistes futures réclamées par ≥ N publications).
 *
 * Les trois détecteurs sont PURS (statiques, testables sans DB) ; {@see detect()}
 * charge, construit et persiste.
 */
final class GapDetector
{
    /** Un concept doit apparaître dans ≥ ce nb de claims pour être « établi » (Swanson). */
    public const MIN_CONCEPT_CLAIMS = 2;
    /** Un résultat doit être étudié par ≥ ce nb de claims pour qu'une case creuse compte. */
    public const MIN_OUTCOME_CLAIMS = 4;
    /** Une population doit être assez fréquente dans le nœud pour qu'on note son absence. */
    public const MIN_POPULATION_CLAIMS = 3;
    /** Nb minimal de publications réclamant une même piste (lacune auto-déclarée). */
    public const MIN_SELF_DECLARED_PUBS = 3;
    /** Plafond de pistes par voie (évite l'explosion combinatoire). */
    public const MAX_PER_STRATEGY = 30;

    /** Méthodes de haut niveau de preuve : leur absence sur un résultat = case creuse. */
    private const STRONG_METHODS = [ClaimMethod::Rct->value, ClaimMethod::MetaAnalysis->value];

    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ResearchGapRepository $gaps,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * (Re)calcule les pistes d'un nœud. Idempotent : purge les anciennes.
     *
     * @return list<ResearchGap>
     */
    public function detect(TreeNode $node): array
    {
        $this->gaps->deleteForNode($node);
        $this->em->flush();

        $claims = $this->claims->findByNode($node);
        $candidates = [
            ...self::missingLinks($claims),
            ...self::sparseCells($claims),
            ...self::selfDeclared($claims),
        ];

        $out = [];
        foreach ($candidates as $c) {
            $gap = $this->toGap($node, $c);
            $this->em->persist($gap);
            $out[] = $gap;
        }
        $this->em->flush();

        return $out;
    }

    // ---------------------------------------------------------------------
    // Voie 1 — chaînon manquant (Swanson ABC) sur le graphe des claims
    // ---------------------------------------------------------------------

    /**
     * @param list<Claim> $claims
     *
     * @return list<array{type:GapType,a:string,b:string,c:string,maturity:float,rarity:float,evidence:int,pubIds:list<int>}>
     */
    public static function missingLinks(array $claims): array
    {
        $edges = [];          // "A\x1fC" => true (relation A→C étudiée)
        $count = [];          // concept => nb de claims l'impliquant
        $succ = [];           // M => [C => true]  (M→C)
        $pred = [];           // M => [A => true]  (A→M)
        foreach ($claims as $cl) {
            $a = $cl->getExposureNorm();
            $c = $cl->getOutcomeNorm();
            if ('' === $a || '' === $c || $a === $c) {
                continue;
            }
            $edges[$a."\x1f".$c] = true;
            $count[$a] = ($count[$a] ?? 0) + 1;
            $count[$c] = ($count[$c] ?? 0) + 1;
            $succ[$a][$c] = true;
            $pred[$c][$a] = true;
        }
        if ([] === $count) {
            return [];
        }
        $maxCount = max($count);

        // Chemins X→M→Y (M = chaînon). Candidat = couple (X,Y) sans lien direct.
        $cand = [];
        foreach (array_keys($count) as $m) {
            $predecessors = array_keys($pred[$m] ?? []);
            $successors = array_keys($succ[$m] ?? []);
            foreach ($predecessors as $x) {
                foreach ($successors as $y) {
                    if ($x === $y) {
                        continue;
                    }
                    if (isset($edges[$x."\x1f".$y]) || isset($edges[$y."\x1f".$x])) {
                        continue; // déjà testé directement → pas une piste
                    }
                    if (($count[$x] ?? 0) < self::MIN_CONCEPT_CLAIMS || ($count[$y] ?? 0) < self::MIN_CONCEPT_CLAIMS) {
                        continue; // extrémités pas assez établies
                    }
                    $key = $x."\x1f".$y;
                    $cand[$key] ??= ['a' => $x, 'b' => $m, 'c' => $y, 'bridges' => []];
                    $cand[$key]['bridges'][$m] = true;
                }
            }
        }

        $out = [];
        foreach ($cand as $c) {
            $mat = min($count[$c['a']], $count[$c['c']]) / $maxCount;
            $out[] = [
                'type' => GapType::MissingLink,
                'a' => $c['a'],
                'b' => $c['b'],
                'c' => $c['c'],
                'maturity' => round($mat, 3),
                'rarity' => 1.0,
                'evidence' => \count($c['bridges']),
                'pubIds' => [],
            ];
        }

        return self::topN($out);
    }

    // ---------------------------------------------------------------------
    // Voie 2 — cellules creuses (résultat × population × méthode)
    // ---------------------------------------------------------------------

    /**
     * @param list<Claim> $claims
     *
     * @return list<array{type:GapType,a:string,b:null,c:string,maturity:float,rarity:float,evidence:int,pubIds:list<int>}>
     */
    public static function sparseCells(array $claims): array
    {
        /** @var array<string,array{count:int,pops:array<string,bool>,methods:array<string,bool>,pubs:array<int,bool>}> $byOutcome */
        $byOutcome = [];
        $populationFreq = [];
        foreach ($claims as $cl) {
            $o = $cl->getOutcomeNorm();
            if ('' === $o) {
                continue;
            }
            $byOutcome[$o] ??= ['count' => 0, 'pops' => [], 'methods' => [], 'pubs' => []];
            ++$byOutcome[$o]['count'];
            $byOutcome[$o]['methods'][$cl->getMethod()->value] = true;
            $byOutcome[$o]['pubs'][(int) $cl->getPublication()->getId()] = true;
            $pop = $cl->getPopulation();
            if (null !== $pop && '' !== trim($pop)) {
                $popNorm = mb_strtolower(trim($pop));
                $byOutcome[$o]['pops'][$popNorm] = true;
                $populationFreq[$popNorm] = ($populationFreq[$popNorm] ?? 0) + 1;
            }
        }

        $commonPops = array_keys(array_filter($populationFreq, static fn (int $n): bool => $n >= self::MIN_POPULATION_CLAIMS));
        $maxOutcome = [] !== $byOutcome ? max(array_map(static fn (array $r): int => $r['count'], $byOutcome)) : 1;

        $out = [];
        foreach ($byOutcome as $outcome => $r) {
            if ($r['count'] < self::MIN_OUTCOME_CLAIMS) {
                continue;
            }
            $mat = $r['count'] / $maxOutcome;
            $pubIds = array_map('intval', array_keys($r['pubs']));

            // (a) jamais par une méthode de haut niveau de preuve
            if ([] === array_intersect(self::STRONG_METHODS, array_keys($r['methods']))) {
                $out[] = [
                    'type' => GapType::SparseCell, 'a' => $outcome, 'b' => null, 'c' => 'essai randomisé / méta-analyse',
                    'maturity' => round($mat, 3), 'rarity' => 1.0, 'evidence' => $r['count'], 'pubIds' => $pubIds,
                ];
            }
            // (b) population courante du nœud jamais croisée avec ce résultat
            foreach ($commonPops as $pop) {
                if (!isset($r['pops'][$pop])) {
                    $out[] = [
                        'type' => GapType::SparseCell, 'a' => $outcome, 'b' => null, 'c' => $pop,
                        'maturity' => round($mat, 3), 'rarity' => 1.0, 'evidence' => $r['count'], 'pubIds' => $pubIds,
                    ];
                }
            }
        }

        return self::topN($out);
    }

    // ---------------------------------------------------------------------
    // Voie 3 — lacunes auto-déclarées (futureWork des auteurs)
    // ---------------------------------------------------------------------

    /**
     * @param list<Claim> $claims
     *
     * @return list<array{type:GapType,a:string,b:null,c:null,maturity:float,rarity:float,evidence:int,pubIds:list<int>}>
     */
    public static function selfDeclared(array $claims): array
    {
        // Regroupe les pistes futures par texte normalisé ; fusionne les variantes
        // proches (Jaccard de tokens ≥ 0,6). Compte les publications DISTINCTES.
        /** @var list<array{label:string,tokens:array<string,bool>,pubs:array<int,bool>}> $clusters */
        $clusters = [];
        foreach ($claims as $cl) {
            $pubId = (int) $cl->getPublication()->getId();
            foreach ($cl->getFutureWork() as $raw) {
                $norm = self::normalize($raw);
                if (mb_strlen($norm) < 6) {
                    continue;
                }
                $tokens = array_fill_keys(array_filter(explode(' ', $norm)), true);
                $merged = false;
                foreach ($clusters as &$cluster) {
                    if (self::jaccard($cluster['tokens'], $tokens) >= 0.6) {
                        $cluster['pubs'][$pubId] = true;
                        $cluster['tokens'] += $tokens;
                        $merged = true;
                        break;
                    }
                }
                unset($cluster);
                if (!$merged) {
                    $clusters[] = ['label' => trim($raw), 'tokens' => $tokens, 'pubs' => [$pubId => true]];
                }
            }
        }

        $out = [];
        foreach ($clusters as $cluster) {
            $pubs = \count($cluster['pubs']);
            if ($pubs < self::MIN_SELF_DECLARED_PUBS) {
                continue;
            }
            $out[] = [
                'type' => GapType::SelfDeclared,
                'a' => mb_substr($cluster['label'], 0, 255),
                'b' => null,
                'c' => null,
                'maturity' => round(min(1.0, $pubs / 6), 3),
                'rarity' => 1.0,
                'evidence' => $pubs,
                'pubIds' => array_map('intval', array_keys($cluster['pubs'])),
            ];
        }

        return self::topN($out);
    }

    // ---------------------------------------------------------------------

    /** @param array<int,array{maturity:float,evidence:int}> $rows */
    private static function topN(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$b['maturity'], $b['evidence']] <=> [$a['maturity'], $a['evidence']]);

        return \array_slice(array_values($rows), 0, self::MAX_PER_STRATEGY);
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * @param array<string,bool> $a
     * @param array<string,bool> $b
     */
    private static function jaccard(array $a, array $b): float
    {
        if ([] === $a || [] === $b) {
            return 0.0;
        }
        $inter = \count(array_intersect_key($a, $b));
        $union = \count($a + $b);

        return 0 === $union ? 0.0 : $inter / $union;
    }

    /**
     * @param array{type:GapType,a:string,b:string|null,c:string|null,maturity:float,rarity:float,evidence:int,pubIds:list<int>} $c
     */
    private function toGap(TreeNode $node, array $c): ResearchGap
    {
        $gap = (new ResearchGap($c['type'], $this->describe($c)))
            ->setTreeNode($node)
            ->setConceptA($c['a'])
            ->setConceptB($c['b'])
            ->setConceptC($c['c'])
            ->setMaturityScore($c['maturity'])
            ->setRarityScore($c['rarity'])
            ->setEvidenceCount($c['evidence'])
            ->setSupportingPublicationIds($c['pubIds']);

        return $gap;
    }

    /**
     * @param array{type:GapType,a:string,b:string|null,c:string|null,evidence:int} $c
     */
    private function describe(array $c): string
    {
        return match ($c['type']) {
            GapType::MissingLink => \sprintf(
                '« %s » et « %s » sont chacun étudiés et reliés indirectement (via « %s »), mais aucune étude ne teste directement « %s → %s ». Chaînon manquant plausible (Swanson).',
                $c['a'], $c['c'], $c['b'], $c['a'], $c['c'],
            ),
            GapType::SparseCell => 'essai randomisé / méta-analyse' === $c['c']
                ? \sprintf('« %s » est étudié, mais jamais par essai randomisé ni méta-analyse — niveau de preuve le plus élevé manquant.', $c['a'])
                : \sprintf('« %s » est étudié, mais jamais sur la population « %s » — case croisée inexplorée.', $c['a'], $c['c']),
            GapType::SelfDeclared => \sprintf('Piste future réclamée par %d publication(s) : « %s ».', $c['evidence'], $c['a']),
        };
    }
}
