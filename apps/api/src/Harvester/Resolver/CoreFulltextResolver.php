<?php

declare(strict_types=1);

namespace App\Harvester\Resolver;

use App\Entity\Publication;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Résolveur CORE (core.ac.uk) — agrégateur de dépôts OA. Cherche l'œuvre par DOI et
 * renvoie son `downloadUrl` (PDF OA). Nécessite une clé API (CORE_API_KEY) ; désactivé
 * si absente.
 */
final class CoreFulltextResolver implements FulltextResolver
{
    private const ENDPOINT = 'https://api.core.ac.uk/v3/search/works';
    private const TIMEOUT = 20;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'CORE_API_KEY')]
        private readonly string $apiKey = '',
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail = '',
    ) {
    }

    public function source(): string
    {
        return 'core';
    }

    public function resolvePdfUrl(Publication $publication): ?string
    {
        if ('' === $this->apiKey) {
            return null; // pas de clé → résolveur inactif
        }
        $doi = $publication->getDoi();
        if (null === $doi || '' === $doi) {
            return null;
        }

        try {
            $data = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => ['q' => 'doi:"'.$doi.'"', 'limit' => 3],
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'User-Agent' => 'SciencesWiki/1.0 (+'.$this->contactEmail.')',
                ],
                'timeout' => self::TIMEOUT,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->info('CORE: résolution échouée', ['doi' => $doi, 'error' => $e->getMessage()]);

            return null;
        }

        foreach ($data['results'] ?? [] as $work) {
            $url = \is_array($work) ? ($work['downloadUrl'] ?? null) : null;
            if (\is_string($url) && str_starts_with($url, 'http')) {
                return $url;
            }
        }

        return null;
    }
}
