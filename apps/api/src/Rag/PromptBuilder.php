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
    public function build(Question $question, array $sources, bool $forArticle = false): array
    {
        // Prompt selon la DESTINATION : rédaction d'article (riche, 5 sections) vs chat
        // interactif (Q/R court). Cf. SettingsService (Option C).
        $system = $forArticle ? $this->settings->articleSystemPrompt() : $this->settings->systemPrompt();

        return [
            LlmMessage::system($system),
            LlmMessage::user($this->user($question, $sources)),
        ];
    }

    /**
     * Rédaction d'article en 2 temps (appel 2) : le rédacteur ne reçoit QUE les faits
     * sourcés extraits à l'étape 1 (pas les résumés bruts) + la liste des références pour la
     * numérotation des citations. Prompt système « rédaction d'article ».
     *
     * @param list<Publication> $sources
     *
     * @return list<LlmMessage>
     */
    public function buildFromFacts(Question $question, string $factsBlock, array $sources): array
    {
        return [
            LlmMessage::system($this->settings->articleSystemPrompt()),
            LlmMessage::user($this->userFromFacts($question, $factsBlock, $sources)),
        ];
    }

    /**
     * @param list<Publication> $sources
     */
    private function userFromFacts(Question $question, string $factsBlock, array $sources): string
    {
        $lines = [
            'QUESTION : '.$question->getText(),
            '',
            "FAITS SOURCÉS VÉRIFIÉS — tu ne dois utiliser QUE ces faits ; n'ajoute AUCUN fait hors de cette liste :",
            $factsBlock,
            '',
            'RÉFÉRENCES (pour la numérotation des citations [n](#source-n)) :',
        ];
        foreach ($sources as $i => $source) {
            $authors = implode(', ', array_map(static fn (array $a): string => $a['name'], $source->getAuthors()));
            $lines[] = \sprintf(
                '[%d] %s — %s (%s). DOI:%s',
                $i + 1,
                $source->getTitle(),
                '' !== $authors ? $authors : 'auteurs inconnus',
                $source->getPublicationDate()?->format('Y') ?? 's.d.',
                $source->getDoi() ?? 'n/a',
            );
        }

        return implode("\n", $lines);
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
