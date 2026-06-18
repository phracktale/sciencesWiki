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

    /** Nombre maximal de tentatives en cas de 429/503 (limite transitoire OpenAlex). */
    private const MAX_ATTEMPTS = 4;

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

        $work = $this->getJson($this->baseUrl.'/works/'.$ref->idInSource, ['mailto' => $this->contactEmail]);

        return $this->mapper->map($work);
    }

    public function getLastCursor(): ?string
    {
        return $this->lastCursor;
    }

    /**
     * Nombre total de travaux OpenAlex correspondant à un filtre (meta.count),
     * via une requête légère (1 résultat). Sert à connaître le volume disponible
     * et ce qu'il reste à moissonner. Renvoie null si indéterminé.
     */
    public function countWorks(string $filter): ?int
    {
        $data = $this->getJson($this->baseUrl.'/works', [
            'per-page' => 1,
            'filter' => $filter,
            'mailto' => $this->contactEmail,
        ]);
        $count = $data['meta']['count'] ?? null;

        return is_numeric($count) ? (int) $count : null;
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

        return $this->getJson($this->baseUrl.'/works', $query);
    }

    /**
     * Requête OpenAlex avec respect du débit (throttle) et résilience aux limites
     * transitoires : un HTTP 429 (Too Many Requests) ou 503 déclenche un nouvel
     * essai après le délai « Retry-After » (ou un backoff exponentiel), jusqu'à
     * {@see self::MAX_ATTEMPTS}. Au-delà, l'exception remonte (le job sera marqué
     * en échec et l'erreur visible dans le suivi de moisson).
     *
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    private function getJson(string $url, array $query): array
    {
        for ($attempt = 1; ; ++$attempt) {
            $this->throttle->tick();

            $response = $this->httpClient->request('GET', $url, [
                'query' => $query,
                'headers' => ['User-Agent' => $this->userAgent()],
            ]);

            $status = $response->getStatusCode();
            if (429 !== $status && 503 !== $status) {
                $this->recordCreditHeaders($response->getHeaders(false));

                return $response->toArray(); // 2xx attendu ; sinon lève une exception explicite
            }

            if ($attempt >= self::MAX_ATTEMPTS) {
                // On joint la raison renvoyée par OpenAlex (corps de la réponse) pour
                // diagnostiquer (limite/seconde, quota, clé requise…) dans le suivi.
                $reason = mb_substr(trim((string) $response->getContent(false)), 0, 300);
                throw new \RuntimeException(\sprintf(
                    'Limite OpenAlex atteinte : HTTP %d après %d tentatives. Réponse : %s',
                    $status, $attempt, '' !== $reason ? $reason : '(corps vide)',
                ));
            }

            // Délai d'attente : en-tête Retry-After si présent, sinon backoff 2^n (borné à 30 s).
            $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 0);
            $delay = $retryAfter > 0 ? min($retryAfter, 30) : min(2 ** $attempt, 30);
            sleep($delay);
        }
    }

    /**
     * Mémorise l'état de limite/crédit OpenAlex annoncé par les en-têtes
     * X-RateLimit-* (limite quotidienne réelle, restant, crédit USD, coût, reset).
     * On n'utilise pas de clé API (polite pool via mailto), mais OpenAlex renvoie
     * tout de même ces valeurs.
     *
     * @param array<string,list<string>> $headers (clés en minuscules)
     */
    private function recordCreditHeaders(array $headers): void
    {
        $num = static function (string $name) use ($headers) {
            $v = $headers[$name][0] ?? null;

            return is_numeric($v) ? $v + 0 : null;
        };

        $this->throttle->recordCredits([
            // Limite quotidienne RÉELLE d'OpenAlex (nombre de requêtes) et restant.
            'openalex.rl.limit' => $num('x-ratelimit-limit'),
            'openalex.rl.remaining' => $num('x-ratelimit-remaining'),
            'openalex.rl.credits_used' => $num('x-ratelimit-credits-used') ?? $num('x-ratelimit-cost-usd'),
            'openalex.rl.reset' => $num('x-ratelimit-reset'),
            // Budget de crédits USD (le cas échéant).
            'openalex.credit.limit_usd' => $num('x-ratelimit-limit-usd'),
            'openalex.credit.remaining_usd' => $num('x-ratelimit-remaining-usd'),
            'openalex.credit.cost_usd' => $num('x-ratelimit-cost-usd'),
        ]);
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
