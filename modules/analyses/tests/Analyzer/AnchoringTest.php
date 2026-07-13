<?php

declare(strict_types=1);

namespace Analyses\Tests\Analyzer;

use Analyses\Analyzer\QuoteAnchoring;
use PHPUnit\Framework\TestCase;

/**
 * Vérification d'ancrage des citations (anti-hallucination) : une citation ancrée doit
 * exister littéralement dans le texte (≥ 6 mots consécutifs), tolérante aux accents/
 * ponctuation. Teste directement le trait QuoteAnchoring.
 */
final class AnchoringTest extends TestCase
{
    /** Petit objet qui expose le trait d'ancrage pour le test. */
    private object $anchorer;

    protected function setUp(): void
    {
        $this->anchorer = new class {
            use QuoteAnchoring;

            public function check(string $quote, string $text): bool
            {
                return $this->quoteInText($quote, $text);
            }
        };
    }

    private function check(string $quote, string $text): bool
    {
        return $this->anchorer->check($quote, $text);
    }

    public function testLiteralQuoteIsAnchored(): void
    {
        $text = 'Participants completed a single online survey about their daily habits and health.';
        self::assertTrue($this->check('Participants completed a single online survey', $text));
    }

    public function testAccentAndPunctuationAreTolerated(): void
    {
        $text = "Les participants ont rempli un questionnaire unique, en ligne, sur leurs habitudes.";
        self::assertTrue($this->check('les participants ont rempli un questionnaire', $text));
    }

    public function testContiguousSpanAnchorsDespiteTruncatedEnds(): void
    {
        $text = 'The cross-sectional study recruited two hundred adult participants from three clinics.';
        self::assertTrue($this->check('the cross sectional study recruited two hundred children', $text));
    }

    public function testHallucinatedQuoteIsRejected(): void
    {
        $text = 'The study used a cross-sectional design with 200 participants surveyed once.';
        self::assertFalse($this->check('A randomized double-blind placebo-controlled trial was conducted over five years', $text));
    }

    public function testTooShortQuoteIsRejected(): void
    {
        $text = 'The study used a cross-sectional design.';
        self::assertFalse($this->check('the study used', $text));
    }
}
