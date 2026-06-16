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
              VULGARISATION et laisse la section ACADEMIQUE vide.
            - Cite tes sources par leur NUMÉRO entre crochets dans le texte, ex.
              [1] ou [2][3]. Le numéro est celui de la SOURCE fournie.
            - La VULGARISATION doit être compréhensible par un ÉLÈVE DE COLLÈGE
              (13-15 ans) : phrases courtes, vocabulaire simple, analogies concrètes,
              tout terme technique expliqué avec des mots simples.
            - La section ACADEMIQUE peut être plus précise/technique : faits établis,
              chacun suivi de sa ou ses citations [n]. Reste neutre et rigoureux.

            Réponds EXACTEMENT avec ces trois sections, dans cet ordre, et rien d'autre :
            ## TITRE
            <un titre court de 2 à 6 mots résumant le sujet, sans ponctuation finale>
            ## VULGARISATION
            <l'explication accessible niveau collège>
            ## ACADEMIQUE
            <les faits établis sourcés ; laisse vide si aucune source pertinente>
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
