<?php

declare(strict_types=1);

namespace App\Ai\Llm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client LLM pour tout endpoint **compatible OpenAI** (`/v1/chat/completions`),
 * dont **Ollama** sur la machine IA dédiée (cf. spec §5.1).
 *
 * `LLM_BASE_URL` pointe vers la base (ex. http://ia.homelab.lan:11434/v1) ; un
 * `LLM_API_TOKEN` optionnel ajoute l'authentification Bearer (réseau privé).
 */
final class OpenAiCompatibleLlmClient implements LlmClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'LLM_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'LLM_MODEL')]
        private readonly string $model,
        #[Autowire(env: 'LLM_API_TOKEN')]
        private readonly string $apiToken = '',
    ) {
    }

    public function complete(array $messages, array $options = []): LlmCompletion
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => array_map(static fn (LlmMessage $m): array => $m->toArray(), $messages),
            'temperature' => $options['temperature'] ?? 0.2,
            // Désactive le « raisonnement » des modèles thinking (Qwen3…) : sinon le
            // contenu visible reste vide (tokens consommés par la réflexion, non
            // diffusée). Surchargable via $options['reasoning_effort'].
            'reasoning_effort' => $options['reasoning_effort'] ?? 'none',
            'stream' => false,
        ];
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }
        if (isset($options['stop'])) {
            $payload['stop'] = $options['stop'];
        }

        $headers = ['Content-Type' => 'application/json'];
        if ('' !== $this->apiToken) {
            $headers['Authorization'] = 'Bearer '.$this->apiToken;
        }

        $data = $this->httpClient->request('POST', $this->chatUrl(), [
            'headers' => $headers,
            'json' => $payload,
            // Timeout d'INACTIVITÉ : pour une réponse non streamée, le serveur
            // n'émet rien tant que la génération n'est pas finie ⇒ il doit couvrir
            // toute la durée de génération (modèle lent / cold-load). Surchargeable.
            'timeout' => $options['timeout'] ?? 300,
        ])->toArray();

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!\is_string($content)) {
            throw new \RuntimeException('Réponse LLM invalide (contenu manquant).');
        }

        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new LlmCompletion(
            content: $content,
            model: \is_string($data['model'] ?? null) ? $data['model'] : $this->model,
            promptTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            completionTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        );
    }

    public function stream(array $messages, array $options = []): iterable
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => array_map(static fn (LlmMessage $m): array => $m->toArray(), $messages),
            'temperature' => $options['temperature'] ?? 0.2,
            // Cf. complete() : désactive le raisonnement (sinon flux vide avec Qwen3).
            'reasoning_effort' => $options['reasoning_effort'] ?? 'none',
            'stream' => true,
        ];
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }

        $headers = ['Content-Type' => 'application/json'];
        if ('' !== $this->apiToken) {
            $headers['Authorization'] = 'Bearer '.$this->apiToken;
        }

        $response = $this->httpClient->request('POST', $this->chatUrl(), [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => 600,
            'buffer' => false,
        ]);

        $buffer = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }
            if ($chunk->isLast()) {
                break;
            }
            $buffer .= $chunk->getContent();
            while (false !== ($pos = strpos($buffer, "\n"))) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ('' === $line || !str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ('[DONE]' === $data) {
                    return;
                }
                try {
                    $json = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                $delta = $json['choices'][0]['delta']['content'] ?? null;
                if (\is_string($delta) && '' !== $delta) {
                    yield $delta;
                }
            }
        }
    }

    public function model(): string
    {
        return $this->model;
    }

    private function chatUrl(): string
    {
        return rtrim($this->baseUrl, '/').'/chat/completions';
    }
}
