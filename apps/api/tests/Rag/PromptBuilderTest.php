<?php

declare(strict_types=1);

namespace App\Tests\Rag;

use App\Entity\Publication;
use App\Entity\Question;
use App\Entity\TreeNode;
use App\Rag\PromptBuilder;
use PHPUnit\Framework\TestCase;

final class PromptBuilderTest extends TestCase
{
    public function testBuildsSourcedPrompt(): void
    {
        $node = new TreeNode('biochimie', 'Biochimie');
        $question = new Question($node, 'Comment dose-t-on une protéine ?');

        $source = (new Publication('Protein measurement with the Folin phenol reagent'))
            ->setDoi('10.1016/s0021-9258(19)52451-6')
            ->setAbstract('Une méthode de dosage des protéines.');

        $messages = (new PromptBuilder())->build($question, [$source]);

        self::assertCount(2, $messages);
        self::assertSame('system', $messages[0]->role);
        self::assertStringContainsString('JSON', $messages[0]->content);
        self::assertStringContainsString('UNIQUEMENT', $messages[0]->content);

        $user = $messages[1]->content;
        self::assertSame('user', $messages[1]->role);
        self::assertStringContainsString('Comment dose-t-on une protéine ?', $user);
        self::assertStringContainsString('[1] Protein measurement', $user);
        self::assertStringContainsString('DOI:10.1016/s0021-9258(19)52451-6', $user);
    }

    public function testHandlesNoSources(): void
    {
        $node = new TreeNode('vide', 'Vide');
        $question = new Question($node, 'Question sans source ?');

        $messages = (new PromptBuilder())->build($question, []);

        self::assertStringContainsString('aucune source disponible', $messages[1]->content);
    }
}
