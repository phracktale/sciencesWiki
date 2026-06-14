<?php

declare(strict_types=1);

namespace App\Ai\Llm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sélectionne l'implémentation du LLM selon l'environnement.
 *
 * - `LLM_DRIVER=openai` (défaut) : endpoint compatible OpenAI/Ollama (prod) ;
 * - `LLM_DRIVER=stub`            : LLM factice (dev/tests).
 */
final class LlmClientFactory
{
    public function __construct(
        private readonly OpenAiCompatibleLlmClient $openai,
        private readonly StubLlmClient $stub,
        #[Autowire(env: 'LLM_DRIVER')]
        private readonly string $driver,
    ) {
    }

    public function create(): LlmClient
    {
        return 'stub' === strtolower($this->driver) ? $this->stub : $this->openai;
    }
}
