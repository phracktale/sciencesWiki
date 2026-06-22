<?php

declare(strict_types=1);

namespace App\Tests\Analysis\Claim;

use App\Analysis\Claim\ClaimJsonParser;
use App\Enum\ClaimConfidence;
use App\Enum\ClaimDirection;
use App\Enum\ClaimMethod;
use PHPUnit\Framework\TestCase;

final class ClaimJsonParserTest extends TestCase
{
    private function parser(): ClaimJsonParser
    {
        return new ClaimJsonParser();
    }

    public function testParsesValidJson(): void
    {
        $raw = '{"claims":[{"exposure":"vitamine D","outcome":"fractures","direction":"negative",'
            .'"method":"rct","confidence":"high","population":"femmes âgées","sample_size":1200,'
            .'"effect_size":"OR 0.8","stated_limitations":"durée courte","future_work":["doses élevées"],'
            .'"quote":"Vitamin D reduced fractures."}]}';

        $parsed = $this->parser()->parse($raw);

        self::assertNotNull($parsed);
        self::assertCount(1, $parsed);
        $claim = $parsed[0];
        self::assertSame('vitamine D', $claim->exposure);
        self::assertSame('fractures', $claim->outcome);
        self::assertSame(ClaimDirection::Negative, $claim->direction);
        self::assertSame(ClaimMethod::Rct, $claim->method);
        self::assertSame(ClaimConfidence::High, $claim->confidence);
        self::assertSame(1200, $claim->sampleSize);
        self::assertSame(['doses élevées'], $claim->futureWork);
    }

    public function testParsesJsonWrappedInCodeFences(): void
    {
        $raw = "Voici le résultat :\n```json\n{\"claims\":[{\"exposure\":\"tabac\",\"outcome\":\"cancer\","
            .'"direction":"positive","method":"cohort","confidence":"moderate","quote":"Smoking raises risk."}]}'
            ."\n```";

        $parsed = $this->parser()->parse($raw);

        self::assertNotNull($parsed);
        self::assertCount(1, $parsed);
        self::assertSame('tabac', $parsed[0]->exposure);
        self::assertSame(ClaimDirection::Positive, $parsed[0]->direction);
    }

    public function testReturnsNullOnBrokenJsonSoCallerRetries(): void
    {
        self::assertNull($this->parser()->parse('{"claims": [ {exposure: pas du json'));
        self::assertNull($this->parser()->parse('désolé, je ne peux pas répondre.'));
    }

    public function testEmptyClaimsArrayIsSuccessNotFailure(): void
    {
        $parsed = $this->parser()->parse('{"claims":[]}');

        self::assertNotNull($parsed);
        self::assertSame([], $parsed);
    }

    public function testSkipsEntriesMissingRequiredFields(): void
    {
        // 1re entrée sans quote → rejetée ; 2e complète → conservée.
        $raw = '{"claims":[{"exposure":"a","outcome":"b","direction":"positive"},'
            .'{"exposure":"c","outcome":"d","direction":"positive","quote":"C affects D."}]}';

        $parsed = $this->parser()->parse($raw);

        self::assertNotNull($parsed);
        self::assertCount(1, $parsed);
        self::assertSame('c', $parsed[0]->exposure);
    }

    public function testUnknownEnumValuesFallBackToSafeDefaults(): void
    {
        $raw = '{"claims":[{"exposure":"a","outcome":"b","direction":"wat","method":"telepathy",'
            .'"confidence":"???","quote":"A relates to B somehow."}]}';

        $parsed = $this->parser()->parse($raw);

        self::assertNotNull($parsed);
        self::assertCount(1, $parsed);
        self::assertSame(ClaimDirection::Unclear, $parsed[0]->direction);
        self::assertSame(ClaimMethod::Other, $parsed[0]->method);
        self::assertSame(ClaimConfidence::Low, $parsed[0]->confidence);
    }

    public function testToleratesTopLevelArray(): void
    {
        $raw = '[{"exposure":"a","outcome":"b","direction":"null","method":"review","confidence":"low","quote":"No effect of A on B."}]';

        $parsed = $this->parser()->parse($raw);

        self::assertNotNull($parsed);
        self::assertCount(1, $parsed);
        self::assertSame(ClaimDirection::Null, $parsed[0]->direction);
    }
}
