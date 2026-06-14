<?php

declare(strict_types=1);

namespace App\Harvester\Oa\Unpaywall;

use App\Harvester\Oa\OaResolution;
use App\Harvester\Oa\OpenAccessResolver;
use App\Harvester\Support\Doi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Résolveur Unpaywall (cf. Phase 1 §3.3) : pour un DOI, renvoie la meilleure
 * version *légalement* accessible et sa licence. C'est le portier légal de
 * l'accès au full-text.
 *
 * L'email est obligatoire ; quota ~100 000 requêtes/jour.
 */
final class UnpaywallResolver implements OpenAccessResolver
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UnpaywallMapper $mapper,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail,
        #[Autowire(env: 'UNPAYWALL_BASE_URL')]
        private readonly string $baseUrl = 'https://api.unpaywall.org/v2',
    ) {
    }

    public function code(): string
    {
        return UnpaywallMapper::SOURCE_CODE;
    }

    public function resolve(string $doi): ?OaResolution
    {
        $normalized = Doi::normalize($doi);
        if (null === $normalized) {
            return null;
        }

        $response = $this->httpClient->request('GET', $this->baseUrl.'/'.rawurlencode($normalized), [
            'query' => ['email' => $this->contactEmail],
            'headers' => ['User-Agent' => $this->userAgent()],
        ]);

        // 404 : DOI inconnu d'Unpaywall.
        if (404 === $response->getStatusCode()) {
            return null;
        }

        return $this->mapper->map($response->toArray());
    }

    private function userAgent(): string
    {
        return \sprintf('SciencesWiki/0.1 (+https://scienceswiki.org; mailto:%s)', $this->contactEmail);
    }
}
