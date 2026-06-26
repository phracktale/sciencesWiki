<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism;

use App\Entity\DuplicationFinding;
use App\Entity\Publication;
use App\Enum\DuplicationType;
use App\Repository\ChunkFingerprintRepository;
use App\Repository\DuplicationFindingRepository;
use App\Repository\PublicationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestration de la détection (cf. docs/spec-plagiat.md §5) : rappel LSH → confirmation
 * Jaccard exact → filtre de légitimité → typage → DuplicationFinding (non décisionnel).
 * Lot 1 : verbatim intra-corpus (l'étage sémantique/paraphrase viendra au lot 2).
 *
 * Seuils en constantes pour le lot 1 ; à basculer dans SettingsService au lot 4 (§9).
 */
final class PlagiarismScanner
{
    private const MAX_PASSAGES = 8;
    private const STRONG_JACCARD = 0.50;
    private const MIN_PASSAGE_JACCARD = 0.30;
    private const NEAR_DUP_OVERLAP = 0.60;
    private const VERBATIM_OVERLAP_MIN = 0.20;
    private const VERBATIM_STRONG_PAIRS = 3;

    /** @var array<int, array<string,bool>> cache des univers de shingles (scan courant) */
    private array $universeCache = [];

    public function __construct(
        private readonly CandidateFinder $candidates,
        private readonly ChunkFingerprintRepository $fingerprints,
        private readonly OverlapScorer $scorer,
        private readonly LegitimacyFilter $legitimacy,
        private readonly PublicationRepository $publications,
        private readonly DuplicationFindingRepository $findings,
        private readonly EntityManagerInterface $em,
        private readonly Connection $conn,
    ) {
    }

    /**
     * Détecte les rapprochements pour une publication. Renvoie le nombre de findings
     * créés/mis à jour.
     */
    public function scan(int $scannedPublicationId): int
    {
        $this->universeCache = [];
        $grouped = $this->candidates->byTargetPublication($scannedPublicationId);
        if ([] === $grouped) {
            return 0;
        }

        $scanned = $this->publications->find($scannedPublicationId);
        if (null === $scanned) {
            return 0;
        }

        // Contenus de tous les chunks impliqués en un seul appel.
        $chunkIds = [];
        foreach ($grouped as $pairs) {
            foreach ($pairs as $p) {
                $chunkIds[$p['srcChunkId']] = true;
                $chunkIds[$p['tgtChunkId']] = true;
            }
        }
        $contents = $this->fingerprints->chunkContents(array_keys($chunkIds));

        $count = 0;
        foreach ($grouped as $tgtPubId => $pairs) {
            $cand = $this->publications->find($tgtPubId);
            if (null === $cand) {
                continue;
            }

            // Confirmation verbatim au niveau couple de fragments.
            $maxJaccard = 0.0;
            $strongPairs = 0;
            $passages = [];
            foreach ($pairs as $p) {
                $sText = $contents[$p['srcChunkId']] ?? '';
                $tText = $contents[$p['tgtChunkId']] ?? '';
                if ($this->legitimacy->shouldSkip($sText) || $this->legitimacy->shouldSkip($tText)) {
                    continue;
                }
                $j = $this->scorer->jaccard($this->scorer->shingleSet($sText), $this->scorer->shingleSet($tText));
                if ($j < self::MIN_PASSAGE_JACCARD) {
                    continue;
                }
                $maxJaccard = max($maxJaccard, $j);
                if ($j >= self::STRONG_JACCARD) {
                    ++$strongPairs;
                }
                $passages[] = [
                    'srcChunkId' => $p['srcChunkId'],
                    'tgtChunkId' => $p['tgtChunkId'],
                    'jaccard' => round($j, 3),
                    'srcText' => $this->excerpt($sText),
                    'tgtText' => $this->excerpt($tText),
                ];
            }
            if ([] === $passages) {
                continue; // proximité purement thématique → écartée
            }

            // Antériorité (§9) : source = la plus récente (copieur présumé), cible = l'antériorité.
            [$src, $tgt] = $this->orderByRecency($scanned, $cand);
            $overlap = $this->scorer->coverage($this->universeOf($src->getId()), $this->universeOf($tgt->getId()));

            $sharesAuthor = $this->sharesAuthor((int) $src->getId(), (int) $tgt->getId());
            $type = $this->classify($overlap, $maxJaccard, $strongPairs, $sharesAuthor);
            if (null === $type) {
                continue;
            }

            usort($passages, static fn (array $a, array $b): int => $b['jaccard'] <=> $a['jaccard']);
            $passages = \array_slice($passages, 0, self::MAX_PASSAGES);

            $finding = $this->findings->findPair($src, $tgt) ?? new DuplicationFinding($src, $tgt, $type);
            $finding->setType($type)
                ->setMetrics($overlap, $maxJaccard, 0.0, $sharesAuthor, $passages)
                ->touchDetectedAt();
            $this->em->persist($finding);
            ++$count;
        }

        $this->em->flush();

        return $count;
    }

    private function classify(float $overlap, float $maxJaccard, int $strongPairs, bool $sharesAuthor): ?DuplicationType
    {
        $type = null;
        if ($overlap >= self::NEAR_DUP_OVERLAP) {
            $type = DuplicationType::NearDuplicate;
        } elseif ($maxJaccard >= self::STRONG_JACCARD && $strongPairs >= self::VERBATIM_STRONG_PAIRS && $overlap >= self::VERBATIM_OVERLAP_MIN) {
            $type = DuplicationType::VerbatimOverlap;
        }
        if (null === $type) {
            return null;
        }

        return $sharesAuthor ? DuplicationType::SelfOverlap : $type;
    }

    /**
     * @return array{0:Publication, 1:Publication} [plus récente (source), plus ancienne (cible)]
     */
    private function orderByRecency(Publication $a, Publication $b): array
    {
        $ta = $a->getPublicationDate()?->getTimestamp() ?? 0;
        $tb = $b->getPublicationDate()?->getTimestamp() ?? 0;
        if ($ta === $tb) {
            return ((int) $a->getId() <=> (int) $b->getId()) >= 0 ? [$a, $b] : [$b, $a];
        }

        return $ta > $tb ? [$a, $b] : [$b, $a];
    }

    /**
     * @return array<string,bool>
     */
    private function universeOf(?int $publicationId): array
    {
        if (null === $publicationId) {
            return [];
        }
        if (isset($this->universeCache[$publicationId])) {
            return $this->universeCache[$publicationId];
        }
        $universe = [];
        foreach ($this->fingerprints->chunksOf($publicationId) as $c) {
            foreach ($this->scorer->shingleSet($c['content']) as $shingle => $_) {
                $universe[$shingle] = true;
            }
        }

        return $this->universeCache[$publicationId] = $universe;
    }

    private function sharesAuthor(int $a, int $b): bool
    {
        return (bool) $this->conn->fetchOne(
            'SELECT 1 FROM authorship a1 JOIN authorship a2 ON a1.author_id = a2.author_id
             WHERE a1.publication_id = :a AND a2.publication_id = :b LIMIT 1',
            ['a' => $a, 'b' => $b],
        );
    }

    private function excerpt(string $text, int $len = 320): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_substr($clean, 0, $len).(mb_strlen($clean) > $len ? '…' : '');
    }
}
