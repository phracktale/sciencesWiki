<?php

declare(strict_types=1);

namespace App\Ai\Llm;

/**
 * Génère du texte à partir d'une conversation (LLM auto-hébergé).
 *
 * Abstraction : la production cible un endpoint compatible OpenAI/Ollama sur la
 * machine IA dédiée ; un stub déterministe permet le dev et les tests sans LLM.
 * Servira à la rédaction des brouillons de vulgarisation ancrés RAG (cf. spec
 * §8.2 et docs/rag-server.md).
 */
interface LlmClient
{
    /**
     * @param list<LlmMessage>                                            $messages
     * @param array{temperature?:float,max_tokens?:int,stop?:list<string>} $options
     */
    public function complete(array $messages, array $options = []): LlmCompletion;

    /**
     * Génération en flux : émet les fragments de texte au fil de l'eau (pour un
     * affichage « machine à écrire »).
     *
     * @param list<LlmMessage>                                            $messages
     * @param array{temperature?:float,max_tokens?:int,stop?:list<string>} $options
     *
     * @return iterable<string>
     */
    public function stream(array $messages, array $options = []): iterable;

    public function model(): string;
}
