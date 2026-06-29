<?php

declare(strict_types=1);

namespace App\Tests\Analysis\Axis;

use App\Analysis\Axis\AxisJsonParser;
use App\Enum\AxisAnswer;
use App\Enum\AxisApplicability;
use PHPUnit\Framework\TestCase;

final class AxisJsonParserTest extends TestCase
{
    private function parser(): AxisJsonParser
    {
        return new AxisJsonParser();
    }

    public function testParsesApplicableAppraisal(): void
    {
        $raw = '{"study_design":"cross-sectional","applicable":true,"items":{'
            .'"q1":{"answer":"yes","quote":null},'
            .'"q3":{"answer":"no","quote":"No sample size calculation was reported."},'
            .'"q13":{"answer":"unclear"}},'
            .'"summary":"Étude transversale correcte."}';

        $parsed = $this->parser()->parse($raw);

        self::assertNotNull($parsed);
        self::assertSame(AxisApplicability::Applicable, $parsed->applicability);
        self::assertSame('cross-sectional', $parsed->studyDesign);
        self::assertSame(AxisAnswer::Yes, $parsed->answers['q1']);
        self::assertSame(AxisAnswer::No, $parsed->answers['q3']);
        self::assertSame(AxisAnswer::Unclear, $parsed->answers['q13']);
        self::assertSame('No sample size calculation was reported.', $parsed->justifications['q3']);
        self::assertArrayNotHasKey('q1', $parsed->justifications);
    }

    public function testNotApplicableSkipsItems(): void
    {
        $raw = '{"study_design":"rct","applicable":false,"summary":"Essai randomisé : AXIS hors-sujet."}';

        $parsed = $this->parser()->parse($raw);

        self::assertNotNull($parsed);
        self::assertSame(AxisApplicability::NotApplicable, $parsed->applicability);
        self::assertSame([], $parsed->answers);
    }

    public function testApplicabilityDeducedFromDesignWhenFlagMissing(): void
    {
        $applicable = $this->parser()->parse('{"study_design":"cross-sectional","items":{"q1":"yes"}}');
        self::assertNotNull($applicable);
        self::assertSame(AxisApplicability::Applicable, $applicable->applicability);

        $notApplicable = $this->parser()->parse('{"study_design":"meta_analysis","items":{}}');
        self::assertNotNull($notApplicable);
        self::assertSame(AxisApplicability::NotApplicable, $notApplicable->applicability);
    }

    public function testParsesJsonWrappedInCodeFences(): void
    {
        $raw = "```json\n{\"study_design\":\"cross-sectional\",\"applicable\":true,\"items\":{\"q1\":{\"answer\":\"yes\"}}}\n```";

        $parsed = $this->parser()->parse($raw);

        self::assertNotNull($parsed);
        self::assertSame(AxisAnswer::Yes, $parsed->answers['q1']);
    }

    public function testReturnsNullOnBrokenJsonSoCallerRetries(): void
    {
        self::assertNull($this->parser()->parse('{"study_design": cross, items: nope'));
        self::assertNull($this->parser()->parse('désolé, je ne peux pas répondre.'));
    }

    public function testToleratesPlainStringItemForm(): void
    {
        $parsed = $this->parser()->parse('{"study_design":"cross-sectional","applicable":true,"items":{"q1":"no"}}');

        self::assertNotNull($parsed);
        self::assertSame(AxisAnswer::No, $parsed->answers['q1']);
        self::assertArrayNotHasKey('q1', $parsed->justifications);
    }

    public function testUnknownAnswerValueIsDropped(): void
    {
        $parsed = $this->parser()->parse('{"study_design":"cross-sectional","applicable":true,"items":{"q1":"maybe","q2":"yes"}}');

        self::assertNotNull($parsed);
        self::assertArrayNotHasKey('q1', $parsed->answers);
        self::assertSame(AxisAnswer::Yes, $parsed->answers['q2']);
    }
}
