<?php

declare(strict_types=1);

namespace Analyses\Sdk;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Port SDK « llm:generate » (SPECS framework §9) : génération via un modèle open source
 * AUTO-HÉBERGÉ (Ollama sur Marvin). Utilisé par le fingerprinter et les analyseurs
 * (extraction/analyse ancrée sur les sources). Mode JSON strict par défaut.
 */
final class LlmPort
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'OLLAMA_URL')]
        private readonly string $baseUrl = 'http://marvin:11434',
    ) {
    }

    /**
     * Génère une réponse JSON structurée.
     *
     * @return array<string, mixed>
     */
    public function generateJson(string $prompt, string $model, int $timeout = 180, ?string $system = null): array
    {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'format' => 'json',
            'stream' => false,
            // Désactive le mode « raisonnement » : certains modèles (glm-4.7-flash…)
            // renvoient sinon le contenu dans « thinking » et laissent « response » vide.
            'think' => false,
        ];
        if (null !== $system && '' !== $system) {
            $payload['system'] = $system;
        }

        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/generate', [
            'json' => $payload,
            'timeout' => $timeout,
        ]);

        $data = $response->toArray(false);
        $text = trim((string) ($data['response'] ?? ''));
        // Repli : si le modèle a tout de même raisonné, le JSON est dans « thinking ».
        if ('' === $text && isset($data['thinking'])) {
            $text = trim((string) $data['thinking']);
        }
        $parsed = json_decode($text, true);

        return \is_array($parsed) ? $parsed : [];
    }
}
