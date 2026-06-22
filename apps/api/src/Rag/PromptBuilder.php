<?php

declare(strict_types=1);

namespace App\Rag;

use App\Ai\Llm\LlmMessage;
use App\Entity\Publication;
use App\Entity\Question;
use App\Service\SettingsService;

/**
 * Assemble le prompt de rédaction sourcée (ancrage RAG). Le prompt système est
 * éditable via le back-office (SettingsService) ; défaut = niveau collège,
 * sections délimitées (cf. spec §8.3).
 */
final class PromptBuilder
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * @param list<Publication> $sources
     *
     * @return list<LlmMessage>
     */
    public function build(Question $question, array $sources): array
    {
        return [
            LlmMessage::system($this->settings->systemPrompt()),
            LlmMessage::user($this->user($question, $sources)),
        ];
    }

    /**
     * @param list<Publication> $sources
     */
    private function user(Question $question, array $sources): string
    {
        $lines = ['QUESTION : '.$question->getText(), '', 'SOURCES :'];

        if ([] === $sources) {
            $lines[] = '(aucune source disponible)';
        }

        foreach ($sources as $i => $source) {
            $n = $i + 1;
            $authors = implode(', ', array_map(static fn (array $a): string => $a['name'], $source->getAuthors()));
            $year = $source->getPublicationDate()?->format('Y') ?? 's.d.';
            $abstract = $source->getAbstract() ?? '(pas de résumé)';
            $lines[] = \sprintf(
                "[%d] %s — %s (%s). DOI:%s\n    Résumé : %s",
                $n,
                $source->getTitle(),
                '' !== $authors ? $authors : 'auteurs inconnus',
                $year,
                $source->getDoi() ?? 'n/a',
                mb_substr($abstract, 0, 700),
            );
        }

        return implode("\n", $lines);
    }
}
