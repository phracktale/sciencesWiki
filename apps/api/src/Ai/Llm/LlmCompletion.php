<?php

declare(strict_types=1);

namespace App\Ai\Llm;

/**
 * Réponse d'une génération LLM.
 */
final class LlmCompletion
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
    ) {
    }
}
