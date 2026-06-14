<?php

declare(strict_types=1);

namespace App\Ai\Llm;

/**
 * Message d'une conversation envoyée au LLM (format compatible OpenAI/Ollama).
 */
final class LlmMessage
{
    public function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {
    }

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    /**
     * @return array{role:string,content:string}
     */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
