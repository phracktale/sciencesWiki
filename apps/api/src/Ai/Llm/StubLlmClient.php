<?php

declare(strict_types=1);

namespace App\Ai\Llm;

/**
 * LLM factice et déterministe pour le dev et les tests : renvoie un brouillon
 * marqué, sans appeler de service externe. Permet de faire tourner le pipeline
 * de rédaction (et de booter l'app) sans LLM disponible.
 */
final class StubLlmClient implements LlmClient
{
    public function complete(array $messages, array $options = []): LlmCompletion
    {
        $lastUser = '';
        foreach ($messages as $message) {
            if ('user' === $message->role) {
                $lastUser = $message->content;
            }
        }

        $content = "[brouillon généré par le LLM factice — non destiné à la publication]\n\n"
            .mb_substr(trim($lastUser), 0, 280);

        return new LlmCompletion($content, 'stub', null, null);
    }

    public function stream(array $messages, array $options = []): iterable
    {
        // Émet le contenu factice mot à mot (simule le flux pour le front).
        foreach (explode(' ', $this->complete($messages, $options)->content) as $i => $word) {
            yield (0 === $i ? '' : ' ').$word;
        }
    }

    public function model(): string
    {
        return 'stub';
    }
}
