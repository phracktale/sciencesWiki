<?php

declare(strict_types=1);

namespace App\Harvester\Connector\Arxiv;

use App\Harvester\Connector\SourceConnector;
use App\Harvester\Dto\DiscoveryCursor;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Dto\RawRef;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connecteur arXiv via OAI-PMH (cf. Phase 1 §3.4) : full-text STEM.
 *
 * - Découverte en masse incrémentale avec `from` + `resumptionToken`.
 * - Rate-limit strict : ≤ 1 requête / 3 s, et respect du 503 + Retry-After.
 */
final class ArxivConnector implements SourceConnector
{
    private const METADATA_PREFIX = 'arXiv';
    private const ARXIV_NS = 'http://arxiv.org/OAI/arXiv/';
    private const MIN_INTERVAL_S = 3;

    private ?string $lastCursor = null;
    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ArxivMapper $mapper,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail,
        #[Autowire(env: 'ARXIV_OAI_URL')]
        private readonly string $baseUrl = 'https://oaipmh.arxiv.org/oai',
    ) {
    }

    public function code(): string
    {
        return ArxivMapper::SOURCE_CODE;
    }

    public function discover(DiscoveryCursor $cursor): iterable
    {
        $token = $cursor->cursor;
        $this->lastCursor = $token;
        $yielded = 0;
        $first = true;

        do {
            $xml = $this->listRecords($first ? $cursor : null, $first ? null : $token);
            $first = false;

            foreach ($xml->ListRecords->record ?? [] as $record) {
                // Ignore les enregistrements supprimés (header status="deleted").
                if ('deleted' === (string) ($record->header['status'] ?? '')) {
                    continue;
                }

                $parsed = $this->parseRecord($record);
                if (null === $parsed) {
                    continue;
                }

                yield new RawRef(
                    sourceCode: $this->code(),
                    idInSource: $parsed['id'],
                    doi: $parsed['doi'],
                    payload: $parsed,
                );

                ++$yielded;
                if (null !== $cursor->maxRecords && $yielded >= $cursor->maxRecords) {
                    return;
                }
            }

            $tokenNode = $xml->ListRecords->resumptionToken ?? null;
            $token = (null !== $tokenNode && '' !== trim((string) $tokenNode)) ? trim((string) $tokenNode) : null;
            $this->lastCursor = $token;
        } while (null !== $token);
    }

    public function fetchMetadata(RawRef $ref): RawPublication
    {
        if (null !== $ref->payload) {
            return $this->mapper->map($ref->payload);
        }

        $xml = $this->request([
            'verb' => 'GetRecord',
            'metadataPrefix' => self::METADATA_PREFIX,
            'identifier' => 'oai:arXiv.org:'.$ref->idInSource,
        ]);

        $record = $xml->GetRecord->record ?? null;
        $parsed = null !== $record ? $this->parseRecord($record) : null;
        if (null === $parsed) {
            throw new \RuntimeException(\sprintf('Enregistrement arXiv introuvable : %s', $ref->idInSource));
        }

        return $this->mapper->map($parsed);
    }

    public function getLastCursor(): ?string
    {
        return $this->lastCursor;
    }

    private function listRecords(?DiscoveryCursor $cursor, ?string $token): \SimpleXMLElement
    {
        // Avec un resumptionToken, l'OAI-PMH interdit tout autre paramètre.
        if (null !== $token) {
            return $this->request(['verb' => 'ListRecords', 'resumptionToken' => $token]);
        }

        $params = ['verb' => 'ListRecords', 'metadataPrefix' => self::METADATA_PREFIX];
        if (null !== $cursor?->since) {
            $params['from'] = $cursor->since->format('Y-m-d');
        }
        if (null !== $cursor?->set) {
            $params['set'] = $cursor->set;
        }

        return $this->request($params);
    }

    /**
     * @param array<string,string> $params
     */
    private function request(array $params): \SimpleXMLElement
    {
        $this->throttle();

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $response = $this->httpClient->request('GET', $this->baseUrl, [
                'query' => $params,
                'headers' => ['User-Agent' => $this->userAgent()],
            ]);

            $status = $response->getStatusCode();

            // arXiv demande de patienter (Retry-After) en cas de surcharge.
            if (503 === $status) {
                $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 10);
                sleep(max(1, $retryAfter));
                continue;
            }

            $content = $response->getContent();
            $xml = simplexml_load_string($content);
            if (false === $xml) {
                throw new \RuntimeException('Réponse OAI-PMH arXiv illisible.');
            }

            // Erreur OAI explicite (sauf « aucun enregistrement », non fatale).
            if (isset($xml->error)) {
                $errorCode = (string) ($xml->error['code'] ?? '');
                if ('noRecordsMatch' !== $errorCode) {
                    throw new \RuntimeException(\sprintf('Erreur OAI-PMH arXiv (%s) : %s', $errorCode, trim((string) $xml->error)));
                }
            }

            return $xml;
        }

        throw new \RuntimeException('arXiv OAI-PMH : trop de 503 successifs.');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseRecord(\SimpleXMLElement $record): ?array
    {
        $meta = $record->metadata ?? null;
        if (null === $meta) {
            return null;
        }

        // L'élément <arXiv> et tous ses descendants sont dans le namespace arXiv :
        // il faut donc redescendre via children($ns) à chaque niveau.
        $arxivNode = $meta->children(self::ARXIV_NS);
        if (0 === $arxivNode->count()) {
            return null;
        }
        $a = $arxivNode->children(self::ARXIV_NS);

        $authors = [];
        foreach ($a->authors->children(self::ARXIV_NS)->author ?? [] as $author) {
            $fields = $author->children(self::ARXIV_NS);
            $authors[] = [
                'keyname' => (string) $fields->keyname,
                'forenames' => (string) $fields->forenames,
                'affiliation' => isset($fields->affiliation) ? (string) $fields->affiliation : null,
            ];
        }

        return [
            'id' => trim((string) $a->id),
            'created' => trim((string) $a->created),
            'doi' => isset($a->doi) ? trim((string) $a->doi) : null,
            'title' => (string) $a->title,
            'abstract' => (string) $a->abstract,
            'categories' => trim((string) $a->categories),
            'license' => isset($a->license) ? trim((string) $a->license) : null,
            'journal_ref' => isset($a->{'journal-ref'}) ? trim((string) $a->{'journal-ref'}) : null,
            'authors' => $authors,
        ];
    }

    /** Garantit l'intervalle minimal entre deux requêtes (≤ 1 / 3 s). */
    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastRequestAt;
        if ($this->lastRequestAt > 0.0 && $elapsed < self::MIN_INTERVAL_S) {
            usleep((int) ((self::MIN_INTERVAL_S - $elapsed) * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);
    }

    private function userAgent(): string
    {
        return \sprintf('SciencesWiki/0.1 (+https://scienceswiki.org; mailto:%s)', $this->contactEmail);
    }
}
