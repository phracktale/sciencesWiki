<?php

declare(strict_types=1);

namespace App\Tests\Analysis\Controversy;

use App\Analysis\Controversy\ClaimCluster;
use App\Analysis\Controversy\ControversyDetector;
use App\Entity\Claim;
use App\Entity\Publication;
use App\Enum\ClaimDirection;
use App\Enum\ClaimMethod;
use App\Enum\DisagreementAxis;
use PHPUnit\Framework\TestCase;

final class ControversyDetectorTest extends TestCase
{
    /**
     * @param list<float>|null $embedding
     */
    private function claim(
        string $exposureNorm,
        string $outcomeNorm,
        ClaimDirection $direction,
        ?array $embedding = null,
        ?string $population = null,
        ClaimMethod $method = ClaimMethod::Observational,
        ?string $year = null,
    ): Claim {
        $publication = new Publication('Étude '.$exposureNorm.' '.$outcomeNorm);
        if (null !== $year) {
            $publication->setPublicationDate(new \DateTimeImmutable($year.'-01-01'));
        }

        $claim = (new Claim($publication, 'stub'))
            ->setExposureLabel($exposureNorm)
            ->setOutcomeLabel($outcomeNorm)
            ->setExposureNorm($exposureNorm)
            ->setOutcomeNorm($outcomeNorm)
            ->setDirection($direction)
            ->setMethod($method)
            ->setConfidence(\App\Enum\ClaimConfidence::Moderate)
            ->setPopulation($population)
            ->setQuote('quote');
        if (null !== $embedding) {
            $claim->setEmbedding($embedding);
        }

        return $claim;
    }

    public function testOpposingDirectionsOnSameAxisAreLitigious(): void
    {
        $clusters = ControversyDetector::cluster([
            $this->claim('cafe', 'tension', ClaimDirection::Positive),
            $this->claim('cafe', 'tension', ClaimDirection::Negative),
            $this->claim('cafe', 'tension', ClaimDirection::Positive),
        ]);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];
        self::assertTrue(ControversyDetector::isLitigious($cluster));
        self::assertEqualsWithDelta(2 / 3, ControversyDetector::consensusScore($cluster), 1e-9);
        self::assertSame(['positive' => 2, 'negative' => 1, 'null' => 0], ControversyDetector::voteCounts($cluster));
    }

    public function testAgreeingClaimsAreNotLitigious(): void
    {
        $clusters = ControversyDetector::cluster([
            $this->claim('sel', 'tension', ClaimDirection::Positive),
            $this->claim('sel', 'tension', ClaimDirection::Positive),
        ]);

        self::assertCount(1, $clusters);
        self::assertFalse(ControversyDetector::isLitigious($clusters[0]));
        self::assertSame(1.0, ControversyDetector::consensusScore($clusters[0]));
    }

    public function testMixedAndUnclearDoNotCountAsDisagreement(): void
    {
        $clusters = ControversyDetector::cluster([
            $this->claim('x', 'y', ClaimDirection::Positive),
            $this->claim('x', 'y', ClaimDirection::Mixed),
            $this->claim('x', 'y', ClaimDirection::Unclear),
        ]);

        self::assertFalse(ControversyDetector::isLitigious($clusters[0]));
    }

    public function testNearbyEmbeddingsMergeReformulatedAxes(): void
    {
        // Deux libellés normalisés différents mais sémantiquement proches
        // (embeddings quasi colinéaires) doivent fusionner ; un 3e axe reste seul.
        $clusters = ControversyDetector::cluster([
            $this->claim('vitamine d', 'fracture', ClaimDirection::Positive, [1.0, 0.0, 0.0]),
            $this->claim('vit d', 'fractures', ClaimDirection::Negative, [0.98, 0.02, 0.0]),
            $this->claim('tabac', 'cancer', ClaimDirection::Positive, [0.0, 1.0, 0.0]),
        ]);

        self::assertCount(2, $clusters);
        // Le cluster fusionné (2 membres) est litigieux ; l'autre non.
        $merged = array_values(array_filter($clusters, static fn (ClaimCluster $c): bool => $c->size() === 2));
        self::assertCount(1, $merged);
        self::assertTrue(ControversyDetector::isLitigious($merged[0]));
    }

    public function testDistantEmbeddingsDoNotMerge(): void
    {
        $clusters = ControversyDetector::cluster([
            $this->claim('a', 'b', ClaimDirection::Positive, [1.0, 0.0, 0.0]),
            $this->claim('c', 'd', ClaimDirection::Negative, [0.0, 1.0, 0.0]),
        ]);

        self::assertCount(2, $clusters);
    }

    public function testHeuristicAxisDistinguishesPopulationMethodAndTime(): void
    {
        $population = new ClaimCluster('a', 'b');
        $population->add($this->claim('a', 'b', ClaimDirection::Positive, null, 'enfants'));
        $population->add($this->claim('a', 'b', ClaimDirection::Negative, null, 'adultes'));
        self::assertSame(DisagreementAxis::Population, ControversyDetector::heuristicAxis($population));

        $method = new ClaimCluster('a', 'b');
        $method->add($this->claim('a', 'b', ClaimDirection::Positive, null, null, ClaimMethod::Rct));
        $method->add($this->claim('a', 'b', ClaimDirection::Negative, null, null, ClaimMethod::Cohort));
        self::assertSame(DisagreementAxis::Method, ControversyDetector::heuristicAxis($method));

        $temporal = new ClaimCluster('a', 'b');
        $temporal->add($this->claim('a', 'b', ClaimDirection::Positive, null, null, ClaimMethod::Observational, '2000'));
        $temporal->add($this->claim('a', 'b', ClaimDirection::Negative, null, null, ClaimMethod::Observational, '2018'));
        self::assertSame(DisagreementAxis::Temporal, ControversyDetector::heuristicAxis($temporal));

        $genuine = new ClaimCluster('a', 'b');
        $genuine->add($this->claim('a', 'b', ClaimDirection::Positive, null, null, ClaimMethod::Observational, '2015'));
        $genuine->add($this->claim('a', 'b', ClaimDirection::Negative, null, null, ClaimMethod::Observational, '2016'));
        self::assertSame(DisagreementAxis::Genuine, ControversyDetector::heuristicAxis($genuine));
    }
}
