<?php

declare(strict_types=1);

namespace App\Rag;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client du garde-fou HHEM (service NLI dédié sur Marvin, cf. ml/hhem). Donne un
 * score de cohérence factuelle (0..1) entre des passages SOURCES et une affirmation
 * générée — bien plus fiable qu'un LLM-juge pour la détection d'hallucination.
 *
 * Désactivé tant que HHEM_URL n'est pas configuré (le service tourne sur Marvin) :
 * dans ce cas l'appelant retombe sur la vérification LLM existante.
 */
final class HhemClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'HHEM_URL')]
        private readonly string $baseUrl = '',
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->baseUrl);
    }

    /**
     * Score d'entailment pour une liste de couples [premise, hypothesis].
     *
     * @param list<array{0:string,1:string}> $pairs
     *
     * @return list<float> scores dans le même ordre (vide en cas d'échec → repli amont)
     */
    public function scoreBatch(array $pairs): array
    {
        if (!$this->isEnabled() || [] === $pairs) {
            return [];
        }
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/score-batch', [
                'json' => ['pairs' => array_values($pairs)],
                'timeout' => 60,
            ]);
            $data = $response->toArray(false);
            $scores = $data['scores'] ?? null;

            return \is_array($scores) ? array_map('floatval', $scores) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
