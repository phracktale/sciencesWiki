<?php

declare(strict_types=1);

namespace App\Rag;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmMessage;
use App\Entity\Question;
use App\Entity\TreeNode;
use App\Enum\QuestionOrigin;
use App\Harvester\Ai\EmbeddingClientFactory;
use App\Repository\QuestionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Propose, via le LLM, quelques questions de vulgarisation « évidentes » pour un
 * nœud (cf. spec §8.2). Les questions sont persistées (origine suggérée IA) et
 * dédoublonnées par nœud + texte.
 */
final class QuestionSuggester
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly EmbeddingClientFactory $embeddingFactory,
        private readonly QuestionRepository $questions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<Question>
     */
    public function suggest(TreeNode $node, int $count): array
    {
        $completion = $this->llm->complete($this->messages($node, $count), ['temperature' => 0.4]);
        $texts = \array_slice(QuestionListParser::parse($completion->content), 0, $count);

        $embedder = $this->embeddingFactory->create();
        $created = [];
        foreach ($texts as $text) {
            if (null !== $this->questions->findOneByNodeAndText($node, $text)) {
                continue;
            }
            $question = new Question($node, $text, QuestionOrigin::SuggeredByAi);
            $question->setEmbedding($embedder->embed($text));
            $this->em->persist($question);
            $created[] = $question;
        }

        return $created;
    }

    /**
     * @return list<LlmMessage>
     */
    private function messages(TreeNode $node, int $count): array
    {
        $system = <<<'TXT'
            Tu aides une encyclopédie de vulgarisation scientifique en français.
            Pour un sujet donné, propose des questions concrètes, évidentes et utiles
            que le grand public se pose réellement. Reste dans le périmètre du sujet.
            Réponds STRICTEMENT par un tableau JSON de chaînes, sans texte autour.
            TXT;

        $user = \sprintf(
            "Sujet : %s\n%s\nPropose %d questions.",
            $node->getLabel(),
            null !== $node->getDescription() ? 'Description : '.$node->getDescription() : '',
            $count,
        );

        return [LlmMessage::system($system), LlmMessage::user($user)];
    }
}
