<?php

declare(strict_types=1);

namespace App\Tests\Analysis\Gap;

use App\Analysis\Gap\GapDetector;
use App\Entity\Claim;
use App\Entity\Publication;
use App\Enum\ClaimConfidence;
use App\Enum\ClaimDirection;
use App\Enum\ClaimMethod;
use App\Enum\GapType;
use PHPUnit\Framework\TestCase;

final class GapDetectorTest extends TestCase
{
    /**
     * @param list<string> $futureWork
     */
    private function claim(
        string $exposureNorm,
        string $outcomeNorm,
        ClaimMethod $method = ClaimMethod::Observational,
        ?string $population = null,
        array $futureWork = [],
        int $pubId = 0,
    ): Claim {
        $publication = new Publication('Étude');
        if (0 !== $pubId) {
            $ref = new \ReflectionProperty(Publication::class, 'id');
            $ref->setValue($publication, $pubId);
        }

        return (new Claim($publication, 'stub'))
            ->setExposureLabel($exposureNorm)
            ->setOutcomeLabel($outcomeNorm)
            ->setExposureNorm($exposureNorm)
            ->setOutcomeNorm($outcomeNorm)
            ->setDirection(ClaimDirection::Positive)
            ->setMethod($method)
            ->setConfidence(ClaimConfidence::Moderate)
            ->setPopulation($population)
            ->setFutureWork($futureWork)
            ->setQuote('q');
    }

    public function testSwansonMissingLinkOnClaimGraph(): void
    {
        // a→b et b→c étudiés (a et c établis ≥2), mais jamais a→c directement.
        $links = GapDetector::missingLinks([
            $this->claim('a', 'b'),
            $this->claim('a', 'b'),
            $this->claim('b', 'c'),
            $this->claim('b', 'c'),
        ]);

        self::assertCount(1, $links);
        self::assertSame(GapType::MissingLink, $links[0]['type']);
        self::assertSame('a', $links[0]['a']);
        self::assertSame('b', $links[0]['b']);
        self::assertSame('c', $links[0]['c']);
    }

    public function testNoMissingLinkWhenDirectRelationExists(): void
    {
        $links = GapDetector::missingLinks([
            $this->claim('a', 'b'),
            $this->claim('a', 'b'),
            $this->claim('b', 'c'),
            $this->claim('b', 'c'),
            $this->claim('a', 'c'), // lien direct → plus une piste
        ]);

        self::assertSame([], $links);
    }

    public function testSparseCellFlagsMissingHighEvidenceMethod(): void
    {
        // Un résultat « o » étudié 4× mais jamais par RCT/méta-analyse.
        $cells = GapDetector::sparseCells([
            $this->claim('x1', 'o'),
            $this->claim('x2', 'o'),
            $this->claim('x3', 'o'),
            $this->claim('x4', 'o'),
        ]);

        self::assertNotEmpty($cells);
        self::assertSame(GapType::SparseCell, $cells[0]['type']);
        self::assertTrue(
            (bool) array_filter($cells, static fn (array $c): bool => 'essai randomisé / méta-analyse' === $c['c']),
            'Une case creuse « méthode de haut niveau de preuve manquante » est attendue.',
        );
    }

    public function testNoSparseCellWhenStrongMethodPresent(): void
    {
        // Étudié par RCT → pas de case creuse « méthode » ; pas de population fréquente.
        $cells = GapDetector::sparseCells([
            $this->claim('x1', 'o', ClaimMethod::Rct),
            $this->claim('x2', 'o'),
            $this->claim('x3', 'o'),
            $this->claim('x4', 'o'),
        ]);

        self::assertSame([], $cells);
    }

    public function testSelfDeclaredClusterAcrossPublications(): void
    {
        // Même piste future réclamée par 3 publications distinctes → lacune.
        $fw = ['étudier l effet à long terme sur les enfants'];
        $gaps = GapDetector::selfDeclared([
            $this->claim('a', 'b', ClaimMethod::Observational, null, $fw, 11),
            $this->claim('c', 'd', ClaimMethod::Observational, null, $fw, 22),
            $this->claim('e', 'f', ClaimMethod::Observational, null, $fw, 33),
        ]);

        self::assertCount(1, $gaps);
        self::assertSame(GapType::SelfDeclared, $gaps[0]['type']);
        self::assertSame(3, $gaps[0]['evidence']);
    }

    public function testSelfDeclaredIgnoresBelowThreshold(): void
    {
        $fw = ['piste réclamée une seule fois ici'];
        $gaps = GapDetector::selfDeclared([
            $this->claim('a', 'b', ClaimMethod::Observational, null, $fw, 11),
            $this->claim('c', 'd', ClaimMethod::Observational, null, $fw, 22),
        ]);

        self::assertSame([], $gaps);
    }
}
