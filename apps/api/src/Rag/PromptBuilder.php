<?php

declare(strict_types=1);

namespace App\Rag;

use App\Ai\Llm\LlmMessage;
use App\Entity\Publication;
use App\Entity\Question;

/**
 * Assemble le prompt de rédaction sourcée (ancrage RAG). Impose : appui
 * **exclusif** sur les sources fournies, séparation bloc académique / bloc
 * vulgarisation, citations numérotées, et sortie JSON stricte (cf. spec §8.3).
 */
final class PromptBuilder
{
    /**
     * @param list<Publication> $sources
     *
     * @return list<LlmMessage>
     */
    public function build(Question $question, array $sources): array
    {
        return [
            LlmMessage::system($this->system()),
            LlmMessage::user($this->user($question, $sources)),
        ];
    }

    private function system(): string
    {
        return <<<'TXT'
            Tu es un rédacteur de vulgarisation scientifique pour SciencesWiki, une
            encyclopédie libre d'éducation populaire en français.

            Règles impératives :
            - Réponds UNIQUEMENT à partir des SOURCES fournies. N'invente aucun fait.
            - Si les sources sont insuffisantes, dis-le explicitement dans la
              vulgarisation et laisse le bloc académique vide.
            - Sépare deux blocs : "academique" (faits établis, sourcés, chaque
              affirmation suivie de sa ou ses citations entre crochets, ex. [1][3])
              et "vulgarisation" (explication pédagogique accessible, en français).
            - Les citations renvoient au NUMÉRO de la source utilisée.
            - Reste neutre, rigoureux, sans extrapolation.

            Réponds STRICTEMENT en JSON, sans texte autour, au format :
            {"academique": "...", "vulgarisation": "...", "citations": [{"marqueur": 1, "source": 1}]}
            où "source" est le numéro d'une source fournie et "marqueur" le numéro
            de note affiché dans le texte.
            TXT;
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
