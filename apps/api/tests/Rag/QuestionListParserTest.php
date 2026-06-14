<?php

declare(strict_types=1);

namespace App\Tests\Rag;

use App\Rag\QuestionListParser;
use PHPUnit\Framework\TestCase;

final class QuestionListParserTest extends TestCase
{
    public function testParsesJsonArray(): void
    {
        $content = 'Voici : ["Qu\'est-ce que l\'ADN ?", "Comment se replie une protéine ?"] fin.';

        self::assertSame(
            ["Qu'est-ce que l'ADN ?", 'Comment se replie une protéine ?'],
            QuestionListParser::parse($content),
        );
    }

    public function testParsesBulletedLines(): void
    {
        $content = "1. Qu'est-ce que la photosynthèse ?\n- Comment les plantes captent la lumière ?\n* Pourquoi les feuilles sont vertes ?";

        self::assertSame(
            [
                "Qu'est-ce que la photosynthèse ?",
                'Comment les plantes captent la lumière ?',
                'Pourquoi les feuilles sont vertes ?',
            ],
            QuestionListParser::parse($content),
        );
    }

    public function testIgnoresTooShortLines(): void
    {
        $content = "ok\nUne vraie question de vulgarisation ?";

        self::assertSame(['Une vraie question de vulgarisation ?'], QuestionListParser::parse($content));
    }
}
