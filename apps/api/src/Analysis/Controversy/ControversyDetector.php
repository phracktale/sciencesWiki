<?php

declare(strict_types=1);

namespace App\Analysis\Controversy;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmMessage;
use App\Entity\Claim;
use App\Entity\Controversy;
use App\Entity\TreeNode;
use App\Enum\DisagreementAxis;
use App\Repository\ClaimRepository;
use App\Repository\ControversyRepository;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Détecte les controverses d'un nœud : regroupe les claims par couple
 * (exposure_norm, outcome_norm), fusionne les reformulations proches par
 * embedding, repère les groupes litigieux (≥ 2 directions distinctes), calcule
 * le score de consensus et tranche l'axe du désaccord
 * (cf. docs/spec-controverses-lacunes.md §6.1).
 *
 * Le clustering ({@see cluster()}) et le scoring sont purs (testables sans DB) ;
 * {@see detect()} charge, persiste et synthétise via LLM.
 */
final class ControversyDetector
{
    /** Distance cosinus en deçà de laquelle deux axes sont fusionnés. */
    public const DEFAULT_THETA = 0.15;

    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ControversyRepository $controversies,
        private readonly LlmClient $llm,
        private readonly SettingsService $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * (Re)calcule les controverses d'un nœud. Idempotent : purge les anciennes.
     *
     * @return list<Controversy>
     */
    public function detect(TreeNode $node, float $theta = self::DEFAULT_THETA): array
    {
        $this->controversies->deleteForNode($node);
        $this->em->flush();

        $clusters = self::cluster($this->claims->findByNode($node), $theta);

        $out = [];
        foreach ($clusters as $cluster) {
            if (!self::isLitigious($cluster)) {
                continue;
            }
            $controversy = $this->buildControversy($node, $cluster);
            $this->em->persist($controversy);
            $out[] = $controversy;
        }
        $this->em->flush();

        return $out;
    }

    /**
     * Regroupe par couple normalisé exact puis fusionne les groupes dont les
     * centroïdes d'embedding sont à distance cosinus < $theta (reformulations).
     *
     * @param list<Claim> $claims
     *
     * @return list<ClaimCluster>
     */
    public static function cluster(array $claims, float $theta = self::DEFAULT_THETA): array
    {
        // 1) Groupes exacts par (exposureNorm, outcomeNorm).
        $exact = [];
        foreach ($claims as $claim) {
            $key = $claim->getExposureNorm()."\x1f".$claim->getOutcomeNorm();
            $exact[$key] ??= new ClaimCluster($claim->getExposureNorm(), $claim->getOutcomeNorm());
            $exact[$key]->add($claim);
        }
        $groups = array_values($exact);

        // 2) Fusion floue : union des groupes proches par centroïde d'embedding.
        $centroids = array_map(static fn (ClaimCluster $g): ?array => self::centroid($g), $groups);
        $n = \count($groups);
        $parent = range(0, max(0, $n - 1));
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                if (null === $centroids[$i] || null === $centroids[$j]) {
                    continue;
                }
                if (self::cosineDistance($centroids[$i], $centroids[$j]) < $theta) {
                    $parent[$find($j)] = $find($i);
                }
            }
        }

        // 3) Matérialise les clusters fusionnés (le représentant garde sa clé).
        $merged = [];
        for ($i = 0; $i < $n; ++$i) {
            $root = $find($i);
            $merged[$root] ??= new ClaimCluster($groups[$root]->exposureNorm, $groups[$root]->outcomeNorm);
            foreach ($groups[$i]->claims as $claim) {
                $merged[$root]->add($claim);
            }
        }

        return array_values($merged);
    }

    /**
     * Décompte des directions retenues pour le vote (positive/negative/null).
     *
     * @return array{positive:int,negative:int,null:int}
     */
    public static function voteCounts(ClaimCluster $cluster): array
    {
        $counts = ['positive' => 0, 'negative' => 0, 'null' => 0];
        foreach ($cluster->claims as $claim) {
            $direction = $claim->getDirection();
            if (!$direction->countsForVote()) {
                continue;
            }
            ++$counts[$direction->value];
        }

        return $counts;
    }

    /** Litigieux ⇔ ≥ 2 directions distinctes parmi {positive, negative, null}. */
    public static function isLitigious(ClaimCluster $cluster): bool
    {
        $counts = self::voteCounts($cluster);
        $distinct = \count(array_filter($counts, static fn (int $c): bool => $c > 0));

        return $distinct >= 2;
    }

    /** Part de la direction majoritaire (1 = consensus, ~0,5 = disputé). */
    public static function consensusScore(ClaimCluster $cluster): float
    {
        $counts = self::voteCounts($cluster);
        $total = array_sum($counts);
        if (0 === $total) {
            return 1.0;
        }

        return max($counts) / $total;
    }

    /**
     * Axe du désaccord, heuristique (cf. spec §6.1.5) : populations distinctes ?
     * méthodes distinctes ? écart de dates > 10 ans ? sinon désaccord genuine.
     */
    public static function heuristicAxis(ClaimCluster $cluster): DisagreementAxis
    {
        $populations = [];
        $methods = [];
        $years = [];
        foreach ($cluster->claims as $claim) {
            if (null !== ($p = $claim->getPopulation()) && '' !== trim($p)) {
                $populations[mb_strtolower(trim($p))] = true;
            }
            $methods[$claim->getMethod()->value] = true;
            $date = $claim->getPublication()->getPublicationDate();
            if (null !== $date) {
                $years[] = (int) $date->format('Y');
            }
        }

        if (\count($populations) >= 2) {
            return DisagreementAxis::Population;
        }
        if (\count($methods) >= 2) {
            return DisagreementAxis::Method;
        }
        if ([] !== $years && (max($years) - min($years)) > 10) {
            return DisagreementAxis::Temporal;
        }

        return DisagreementAxis::Genuine;
    }

    private function buildControversy(TreeNode $node, ClaimCluster $cluster): Controversy
    {
        $counts = self::voteCounts($cluster);

        $controversy = new Controversy($node, $cluster->exposureNorm, $cluster->outcomeNorm);
        $controversy
            ->setCounts($counts['positive'], $counts['negative'], $counts['null'])
            ->setConsensusScore(self::consensusScore($cluster))
            ->setDisagreementAxis(self::heuristicAxis($cluster))
            ->setSummary($this->summarize($cluster));

        foreach ($cluster->claims as $claim) {
            $controversy->addClaim($claim);
        }

        return $controversy;
    }

    /**
     * Synthèse courte sourcée [n] (n = rang du claim dans la controverse).
     * Best-effort LLM ; repli sur un résumé factuel si la sortie est vide.
     */
    private function summarize(ClaimCluster $cluster): string
    {
        $opts = ['temperature' => 0.2, 'max_tokens' => 320];
        if (null !== ($m = $this->settings->model())) {
            $opts['model'] = $m;
        }

        try {
            $content = trim($this->llm->complete($this->summaryMessages($cluster), $opts)->content);
            $content = (string) preg_replace('/^```[a-z]*\s*|\s*```$/mi', '', $content);
            if ('' !== trim($content)) {
                return trim($content);
            }
        } catch (\Throwable) {
            // Repli déterministe ci-dessous.
        }

        return $this->factualSummary($cluster);
    }

    /**
     * @return list<LlmMessage>
     */
    private function summaryMessages(ClaimCluster $cluster): array
    {
        $lines = [];
        foreach ($cluster->claims as $i => $claim) {
            $doi = $claim->getPublication()->getDoi() ?? 'sans DOI';
            $lines[] = \sprintf(
                '[%d] %s → %s : %s (%s, %s) — %s',
                $i + 1,
                $claim->getExposureLabel(),
                $claim->getOutcomeLabel(),
                $claim->getDirection()->value,
                $claim->getMethod()->value,
                $claim->getPopulation() ?? 'population n.p.',
                $doi,
            );
        }

        $system = "Tu es un assistant d'analyse scientifique. À partir d'assertions "
            ."numérotées portant sur une même relation, rédige en 2 phrases MAXIMUM, en "
            ."français, la nature du désaccord : vrai désaccord ou artefact "
            ."(population/méthode/dose/époque). Cite les sources par leur numéro [n]. "
            .'Ne conclus pas au-delà des données. Réponds sans bloc de code.';

        return [
            LlmMessage::system($system),
            LlmMessage::user("Relation disputée. Assertions :\n".implode("\n", $lines)),
        ];
    }

    private function factualSummary(ClaimCluster $cluster): string
    {
        $counts = self::voteCounts($cluster);

        return \sprintf(
            'Résultats divergents sur « %s → %s » : %d en faveur, %d en défaveur, %d sans effet (consensus %.0f%%, axe : %s).',
            $cluster->exposureNorm,
            $cluster->outcomeNorm,
            $counts['positive'],
            $counts['negative'],
            $counts['null'],
            self::consensusScore($cluster) * 100,
            self::heuristicAxis($cluster)->value,
        );
    }

    /**
     * Centroïde des embeddings d'un groupe (moyenne), ou null si aucun embedding.
     *
     * @return list<float>|null
     */
    private static function centroid(ClaimCluster $cluster): ?array
    {
        $sum = null;
        $n = 0;
        foreach ($cluster->claims as $claim) {
            $vec = $claim->getEmbedding();
            if (null === $vec) {
                continue;
            }
            $arr = $vec->toArray();
            $sum ??= array_fill(0, \count($arr), 0.0);
            foreach ($arr as $k => $v) {
                $sum[$k] += (float) $v;
            }
            ++$n;
        }
        if (null === $sum || 0 === $n) {
            return null;
        }

        return array_map(static fn (float $v): float => $v / $n, $sum);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosineDistance(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $len = min(\count($a), \count($b));
        for ($i = 0; $i < $len; ++$i) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if (0.0 === $na || 0.0 === $nb) {
            return 1.0;
        }

        return 1.0 - ($dot / (sqrt($na) * sqrt($nb)));
    }
}
