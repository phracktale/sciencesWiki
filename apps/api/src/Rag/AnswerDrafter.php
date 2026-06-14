<?php

declare(strict_types=1);

namespace App\Rag;

use App\Ai\Llm\LlmClient;
use App\Entity\Answer;
use App\Entity\AnswerRevision;
use App\Entity\Footnote;
use App\Entity\Publication;
use App\Entity\Question;
use App\Enum\AnswerType;
use App\Enum\RevisionAuthorType;
use App\Harvester\Ai\EmbeddingClientFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère le brouillon de réponse (Q/R) ancré RAG (cf. spec §8.2/§8.4) :
 * récupération → prompt sourcé → LLM → parsing → persistance (Answer +
 * AnswerRevision IA + notes de bas de page). Non publié : passe en relecture.
 */
final class AnswerDrafter
{
    public function __construct(
        private readonly RagRetriever $retriever,
        private readonly PromptBuilder $promptBuilder,
        private readonly LlmClient $llm,
        private readonly EmbeddingClientFactory $embeddingFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function draft(Question $question, AnswerType $type, int $k = 5): Answer
    {
        if (null === $question->getEmbedding()) {
            $question->setEmbedding($this->embeddingFactory->create()->embed($question->getText()));
        }

        $sources = $this->retriever->retrieve($question, $k);
        $messages = $this->promptBuilder->build($question, $sources);
        $completion = $this->llm->complete($messages, ['temperature' => 0.2, 'max_tokens' => 1200]);

        $parsed = $this->parse($completion->content, $sources);

        $answer = new Answer($question, $type);
        $revision = (new AnswerRevision(RevisionAuthorType::Ai))
            ->setAcademicContent($parsed['academic'])
            ->setVulgarizationContent($parsed['vulgarization'])
            ->setChangeSummary(\sprintf('Brouillon initial généré par IA (%s)', $this->llm->model()));
        $answer->addRevision($revision);

        foreach ($parsed['footnotes'] as $footnote) {
            $revision->addFootnote(new Footnote($footnote['publication'], $footnote['marker']));
        }

        $this->em->persist($question);
        $this->em->persist($answer);

        return $answer;
    }

    /**
     * @param list<Publication> $sources
     *
     * @return array{academic:string,vulgarization:string,footnotes:list<array{marker:int,publication:Publication}>}
     */
    private function parse(string $content, array $sources): array
    {
        $json = $this->extractJson($content);
        if (null !== $json) {
            $footnotes = [];
            $seen = [];
            foreach (($json['citations'] ?? []) as $citation) {
                $sourceIndex = (int) ($citation['source'] ?? 0) - 1;
                $marker = (int) ($citation['marqueur'] ?? 0);
                if (!isset($sources[$sourceIndex]) || isset($seen[$marker])) {
                    continue;
                }
                $seen[$marker] = true;
                $footnotes[] = ['marker' => $marker, 'publication' => $sources[$sourceIndex]];
            }

            return [
                'academic' => trim((string) ($json['academique'] ?? '')),
                'vulgarization' => trim((string) ($json['vulgarisation'] ?? '')),
                'footnotes' => $footnotes,
            ];
        }

        // Sortie non structurée (ex. LLM stub) : on conserve le texte en
        // vulgarisation et on rattache toutes les sources récupérées.
        $footnotes = [];
        foreach ($sources as $i => $source) {
            $footnotes[] = ['marker' => $i + 1, 'publication' => $source];
        }

        return ['academic' => '', 'vulgarization' => trim($content), 'footnotes' => $footnotes];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractJson(string $content): ?array
    {
        $content = trim($content);
        // Retire d'éventuelles barrières Markdown ```json ... ```.
        $content = (string) preg_replace('/^```(?:json)?|```$/m', '', $content);

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if (false === $start || false === $end || $end < $start) {
            return null;
        }

        try {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
