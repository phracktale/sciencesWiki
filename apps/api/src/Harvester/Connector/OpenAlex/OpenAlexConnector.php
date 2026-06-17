<?php

declare(strict_types=1);

namespace App\Harvester\Connector\OpenAlex;

use App\Harvester\Connector\SourceConnector;
use App\Harvester\Dto\DiscoveryCursor;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Dto\RawRef;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connecteur OpenAlex (cf. Phase 1 §3.2) : socle de découverte et de métadonnées.
 *
 * Sans clé d'API. On rejoint le « polite pool » via le paramètre `mailto`, et on
 * pagine avec le cursor paging (`cursor=*`).
 */
final class OpenAlexConnector implements SourceConnector
{
    private const PER_PAGE = 200;

    private ?string $lastCursor = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OpenAlexMapper $mapper,
        private readonly \App\Harvester\OpenAlexThrottle $throttle,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail,
        #[Autowire(env: 'OPENALEX_BASE_URL')]
        private readonly string $baseUrl = 'https://api.openalex.org',
    ) {
    }

    public function code(): string
    {
        return OpenAlexMapper::SOURCE_CODE;
    }

    public function discover(DiscoveryCursor $cursor): iterable
    {
        $cursorToken = $cursor->cursor ?? '*';
        $this->lastCursor = $cursorToken;
        $yielded = 0;

        while (null !== $cursorToken) {
            $data = $this->request($cursorToken, $cursor->since, $cursor->filter);
            $results = \is_array($data['results'] ?? null) ? $data['results'] : [];

            foreach ($results as $work) {
                if (!\is_array($work)) {
                    continue;
                }

                yield new RawRef(
                    sourceCode: $this->code(),
                    idInSource: self::shortId((string) ($work['id'] ?? '')),
                    doi: isset($work['doi']) ? (string) $work['doi'] : null,
                    payload: $work,
                );

                ++$yielded;
                if (null !== $cursor->maxRecords && $yielded >= $cursor->maxRecords) {
                    return;
                }
            }

            $meta = \is_array($data['meta'] ?? null) ? $data['meta'] : [];
            $next = $meta['next_cursor'] ?? null;
            $cursorToken = (\is_string($next) && '' !== $next && [] !== $results) ? $next : null;
            $this->lastCursor = $cursorToken ?? $this->lastCursor;
        }
    }

    public function fetchMetadata(RawRef $ref): RawPublication
    {
        if (null !== $ref->payload) {
            return $this->mapper->map($ref->payload);
        }

        $this->throttle->tick();
        $work = $this->httpClient->request('GET', $this->baseUrl.'/works/'.$ref->idInSource, [
            'query' => ['mailto' => $this->contactEmail],
            'headers' => ['User-Agent' => $this->userAgent()],
        ])->toArray();

        return $this->mapper->map($work);
    }

    public function getLastCursor(): ?string
    {
        return $this->lastCursor;
    }

    /**
     * @return array<string,mixed>
     */
    private function request(string $cursor, ?\DateTimeImmutable $since, ?string $extraFilter = null): array
    {
        $query = [
            'per-page' => self::PER_PAGE,
            'cursor' => $cursor,
            'mailto' => $this->contactEmail,
        ];

        // Filtres OpenAlex combinés (ET = séparés par des virgules).
        $filters = [];
        if (null !== $since) {
            $filters[] = 'from_updated_date:'.$since->format('Y-m-d');
        }
        if (null !== $extraFilter && '' !== $extraFilter) {
            $filters[] = $extraFilter;
        }
        if ([] !== $filters) {
            $query['filter'] = implode(',', $filters);
        }

        $this->throttle->tick();

        return $this->httpClient->request('GET', $this->baseUrl.'/works', [
            'query' => $query,
            'headers' => ['User-Agent' => $this->userAgent()],
        ])->toArray();
    }

    private function userAgent(): string
    {
        return \sprintf('SciencesWiki/0.1 (+https://scienceswiki.org; mailto:%s)', $this->contactEmail);
    }

    private static function shortId(string $value): string
    {
        $pos = strrpos($value, '/');

        return false === $pos ? $value : substr($value, $pos + 1);
    }
}
