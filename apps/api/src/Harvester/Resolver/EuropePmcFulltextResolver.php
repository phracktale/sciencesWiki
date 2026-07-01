<?php

declare(strict_types=1);

namespace App\Harvester\Resolver;

use App\Entity\Publication;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Résolveur Europe PMC — texte intégral biomédical en accès libre. Cherche l'article
 * par DOI et renvoie l'URL d'un PDF marqué « Open access » dans sa liste de liens
 * plein texte. Sans clé (pool poli via User-Agent + e-mail de contact).
 */
final class EuropePmcFulltextResolver implements FulltextResolver
{
    private const ENDPOINT = 'https://www.ebi.ac.uk/europepmc/webservices/rest/search';
    private const TIMEOUT = 20;
    // « Open access » strict = sous-ensemble OA d'Europe PMC (PDF de rendu fiable).
    // On EXCLUT « Free » : ces articles hors sous-ensemble OA renvoient un PDF cassé (500).
    private const OA = ['Open access'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail = '',
    ) {
    }

    public function source(): string
    {
        return 'europe_pmc';
    }

    public function resolvePdfUrl(Publication $publication): ?string
    {
        $doi = $publication->getDoi();
        if (null === $doi || '' === $doi) {
            return null;
        }

        try {
            $data = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => [
                    'query' => 'DOI:"'.$doi.'"',
                    'format' => 'json',
                    'resultType' => 'core',
                    'pageSize' => 1,
                    'email' => $this->contactEmail,
                ],
                'headers' => ['User-Agent' => 'SciencesWiki/1.0 (+'.$this->contactEmail.')'],
                'timeout' => self::TIMEOUT,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->info('Europe PMC: résolution échouée', ['doi' => $doi, 'error' => $e->getMessage()]);

            return null;
        }

        $result = $data['resultList']['result'][0] ?? null;
        if (!\is_array($result)) {
            return null;
        }

        foreach ($result['fullTextUrlList']['fullTextUrl'] ?? [] as $link) {
            if (!\is_array($link)) {
                continue;
            }
            $url = $link['url'] ?? null;
            if ('pdf' === ($link['documentStyle'] ?? '')
                && \in_array($link['availability'] ?? '', self::OA, true)
                && \is_string($url) && str_starts_with($url, 'http')) {
                return $url;
            }
        }

        return null;
    }
}
