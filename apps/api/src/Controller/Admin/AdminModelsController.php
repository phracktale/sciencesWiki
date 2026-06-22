<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Liste les modèles disponibles sur le serveur d'inférence (Ollama sur Marvin,
 * endpoint compatible OpenAI). Permet de choisir/comparer un modèle dans les
 * paramètres IA. Lecture seule (ROLE_ADMIN).
 */
final class AdminModelsController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'LLM_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'LLM_API_TOKEN')]
        private readonly string $apiToken = '',
        #[Autowire(env: 'LLM_MODEL')]
        private readonly string $defaultModel = '',
    ) {
    }

    #[Route('/api/admin/models', name: 'admin_models', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $headers = [];
        if ('' !== $this->apiToken) {
            $headers['Authorization'] = 'Bearer '.$this->apiToken;
        }

        $models = [];
        $error = null;
        try {
            // Endpoint OpenAI-compatible : GET {base}/models (Ollama le gère).
            $data = $this->httpClient->request('GET', rtrim($this->baseUrl, '/').'/models', [
                'headers' => $headers,
                'timeout' => 8,
            ])->toArray(false);

            foreach (($data['data'] ?? $data['models'] ?? []) as $m) {
                $id = \is_array($m) ? ($m['id'] ?? $m['name'] ?? null) : (\is_string($m) ? $m : null);
                if (\is_string($id) && '' !== $id) {
                    $models[] = $id;
                }
            }
            sort($models);
        } catch (\Throwable $e) {
            $error = 'Serveur d\'inférence injoignable : '.$e->getMessage();
        }

        return new JsonResponse([
            'models' => array_values(array_unique($models)),
            'default' => $this->defaultModel,
            'error' => $error,
        ]);
    }
}
