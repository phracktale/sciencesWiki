<?php

declare(strict_types=1);

namespace Analyses\Tests\Analyzer;

use Analyses\Analyzer\AxisAnalyzer;
use Analyses\Sdk\LlmPort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Vérification d'ancrage des citations (anti-hallucination) : une citation ancrée doit
 * exister littéralement dans le texte (≥ 6 mots consécutifs), tolérante aux accents/
 * ponctuation. Teste la méthode privée quoteInText via réflexion.
 */
final class AnchoringTest extends TestCase
{
    private function quoteInText(string $quote, string $text): bool
    {
        // LlmPort n'est pas appelé ici (quoteInText est purement textuel).
        $analyzer = new AxisAnalyzer(new LlmPort(HttpClient::create()));
        $method = new \ReflectionMethod($analyzer, 'quoteInText');
        $method->setAccessible(true);

        return (bool) $method->invoke($analyzer, $quote, $text);
    }

    public function testLiteralQuoteIsAnchored(): void
    {
        $text = 'Participants completed a single online survey about their daily habits and health.';
        self::assertTrue($this->quoteInText('Participants completed a single online survey', $text));
    }

    public function testAccentAndPunctuationAreTolerated(): void
    {
        $text = "Les participants ont rempli un questionnaire unique, en ligne, sur leurs habitudes.";
        self::assertTrue($this->quoteInText('les participants ont rempli un questionnaire', $text));
    }

    public function testContiguousSpanAnchorsDespiteTruncatedEnds(): void
    {
        $text = 'The cross-sectional study recruited two hundred adult participants from three clinics.';
        // La fin diverge, mais une séquence de 6 mots reste présente.
        self::assertTrue($this->quoteInText('the cross sectional study recruited two hundred children', $text));
    }

    public function testHallucinatedQuoteIsRejected(): void
    {
        $text = 'The study used a cross-sectional design with 200 participants surveyed once.';
        self::assertFalse($this->quoteInText('A randomized double-blind placebo-controlled trial was conducted over five years', $text));
    }

    public function testTooShortQuoteIsRejected(): void
    {
        $text = 'The study used a cross-sectional design.';
        self::assertFalse($this->quoteInText('the study used', $text));
    }
}
