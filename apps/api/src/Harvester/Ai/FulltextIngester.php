<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

use App\Entity\Publication;
use App\Harvester\Resolver\FulltextResolver;
use Doctrine\DBAL\Connection;
use Pgvector\Vector;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Récupère le PDF en accès libre d'une publication (sur le site de l'éditeur / le
 * dépôt OA, pas via l'API OpenAlex), en extrait le texte (pdftotext), le découpe
 * en fragments et calcule leurs embeddings → table publication_chunk. Ces
 * fragments enrichissent le RAG au-delà du seul résumé.
 *
 * Idempotent et borné : ne traite que les publications OA pas encore récupérées,
 * limite le nombre de fragments par article. Tolérant aux pannes (PDF illisible,
 * page HTML au lieu d'un PDF, lien mort) : marque la tentative et passe.
 */
final class FulltextIngester
{
    private const MAX_PDF_BYTES = 25_000_000;   // 25 Mo : au-delà, on ignore
    private const CHUNK_CHARS = 1500;
    private const MAX_CHUNKS = 80;              // borne le coût d'embeddings par article

    private readonly EmbeddingClient $embedder;

    /**
     * @param iterable<FulltextResolver> $resolvers repli OA (CORE, Europe PMC) si pas d'oa_url
     */
    public function __construct(
        EmbeddingClientFactory $factory,
        private readonly HttpClientInterface $httpClient,
        private readonly Connection $conn,
        private readonly LoggerInterface $logger,
        private readonly GrobidExtractor $grobid,
        #[AutowireIterator('app.fulltext_resolver')]
        private readonly iterable $resolvers = [],
    ) {
        $this->embedder = $factory->create();
    }

    /**
     * Tente d'ingérer le texte intégral d'une publication OA. Retourne le nombre
     * de fragments créés (0 si ignoré/échec). Voie privilégiée : PDF éditeur →
     * GROBID (TEI structuré) ; repli : pdftotext.
     */
    public function ingest(Publication $publication): int
    {
        $id = $publication->getId();
        if (null === $id) {
            return 0;
        }

        // URLs candidates dans l'ordre : oa_url direct, sinon PDF OA résolus (Europe
        // PMC, CORE) via le DOI. On les essaie une à une jusqu'à ce qu'un téléchargement
        // produise des fragments (une URL de résolveur peut échouer → on tente la suivante).
        $urls = $this->candidateUrls($publication);

        // On marque la tentative tout de suite pour ne pas réessayer en boucle.
        $this->conn->executeStatement('UPDATE publication SET fulltext_fetched_at = now() WHERE id = :id', ['id' => $id]);

        foreach ($urls as $url) {
            try {
                $pdf = $this->download($url);
                if (null === $pdf) {
                    continue;
                }
                [$chunks, $source] = $this->pdfToChunks($pdf);
                @unlink($pdf);
                if ([] === $chunks) {
                    continue;
                }

                return $this->storeChunks($id, $chunks, $source);
            } catch (\Throwable $e) {
                $this->logger->info('Texte intégral non ingéré : '.$e->getMessage(), ['publication' => $id, 'url' => $url]);
            }
        }

        return 0;
    }

    /**
     * URLs candidates de PDF, par préférence : oa_url direct ; sinon les résolveurs OA
     * (CORE, Europe PMC) via le DOI.
     *
     * @return list<string>
     */
    private function candidateUrls(Publication $publication): array
    {
        $oa = $publication->getOaUrl();
        if (null !== $oa && '' !== $oa) {
            return [$oa];
        }
        if (null === $publication->getDoi()) {
            return [];
        }

        $urls = [];
        foreach ($this->resolvers as $resolver) {
            try {
                $url = $resolver->resolvePdfUrl($publication);
            } catch (\Throwable $e) {
                $this->logger->info('Résolveur '.$resolver->source().' en échec', ['publication' => $publication->getId(), 'error' => $e->getMessage()]);
                continue;
            }
            if (null !== $url && '' !== $url) {
                $this->logger->info('PDF OA résolu', ['publication' => $publication->getId(), 'source' => $resolver->source()]);
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Extrait des fragments d'un PDF local : GROBID (TEI, sections) si disponible,
     * sinon pdftotext. Retourne [fragments, provenance].
     *
     * @return array{0: list<string>, 1: string}
     */
    private function pdfToChunks(string $pdfPath): array
    {
        $chunks = $this->grobid->extract($pdfPath, self::MAX_CHUNKS);
        if ([] !== $chunks) {
            return [$chunks, 'grobid_self'];
        }
        // Repli : extraction plate via pdftotext.
        $text = $this->extractText($pdfPath);
        if (null === $text || mb_strlen($text) < 500) {
            return [[], 'publisher'];
        }

        return [$this->chunk($text), 'publisher'];
    }

    /**
     * Vectorise + persiste des fragments pour une publication (purge préalable),
     * marque le texte intégral disponible + sa provenance. Retourne le nb de fragments.
     *
     * @param list<string> $chunks
     */
    private function storeChunks(int $publicationId, array $chunks, string $source): int
    {
        $vectors = $this->embedder->embedBatch($chunks);
        $this->conn->executeStatement('DELETE FROM publication_chunk WHERE publication_id = :id', ['id' => $publicationId]);

        $ord = 0;
        foreach ($chunks as $i => $chunk) {
            if (!isset($vectors[$i])) {
                continue;
            }
            $this->conn->executeStatement(
                'INSERT INTO publication_chunk (publication_id, ord, content, embedding)
                 VALUES (:p, :o, :c, CAST(:v AS halfvec))',
                ['p' => $publicationId, 'o' => $ord++, 'c' => $chunk, 'v' => (string) new Vector($vectors[$i])],
            );
        }
        if ($ord > 0) {
            $this->conn->executeStatement(
                'UPDATE publication SET fulltext_available = true, fulltext_stored = true, fulltext_source = :s WHERE id = :id',
                ['s' => $source, 'id' => $publicationId],
            );
        }

        return $ord;
    }

    /**
     * Ingère un PDF DÉJÀ présent sur le disque (version auteur déposée via le
     * formulaire de contribution) : extraction du texte, découpage, embeddings →
     * publication_chunk. Retourne le nombre de fragments créés. Idempotent :
     * purge d'abord les fragments existants de cette publication.
     */
    public function ingestUploadedPdf(int $publicationId, string $pdfPath): int
    {
        [$chunks] = $this->pdfToChunks($pdfPath);
        if ([] === $chunks) {
            return 0;
        }
        $ord = $this->storeChunks($publicationId, $chunks, 'author');
        if ($ord > 0) {
            $this->conn->executeStatement(
                'UPDATE publication SET author_pdf_at = now(), fulltext_fetched_at = now() WHERE id = :id',
                ['id' => $publicationId],
            );
        }

        return $ord;
    }

    /**
     * Télécharge le PDF de l'URL. Suit les redirections NATIVEMENT (y compris le
     * « cookie dance » de certains éditeurs, ex. nature.com → idp → pdf), tout en
     * bloquant l'accès aux réseaux privés/réservés à CHAQUE saut via
     * NoPrivateNetworkHttpClient (anti-SSRF, redirections comprises).
     * Si la cible est une page HTML, tente d'y découvrir le PDF via citation_pdf_url
     * (une seule fois). Paywalls (page de login non-%PDF) → rejetés.
     */
    private function download(string $url, bool $allowDiscovery = true): ?string
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $client = new NoPrivateNetworkHttpClient($this->httpClient);
        try {
            $response = $client->request('GET', $url, [
                'timeout' => 40,
                'max_redirects' => 8,
                'headers' => [
                    'Accept' => 'application/pdf,*/*',
                    'User-Agent' => 'SciencesWikiBot/1.0 (+https://scienceswiki.eu; mailto:contact@scienceswiki.org)',
                ],
            ]);
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $final = (string) ($response->getInfo('url') ?: $url);
            $contentType = strtolower($response->getHeaders(false)['content-type'][0] ?? '');
            $isPdf = str_contains($contentType, 'pdf') || str_ends_with(strtolower((string) parse_url($final, \PHP_URL_PATH)), '.pdf');
            if (!$isPdf) {
                // Page HTML : découverte du PDF via citation_pdf_url (une fois).
                if ($allowDiscovery && str_contains($contentType, 'html')) {
                    $html = mb_substr((string) $response->getContent(false), 0, 600_000);
                    $pdfUrl = $this->extractCitationPdfUrl($html, $final);
                    if (null !== $pdfUrl && $pdfUrl !== $final) {
                        return $this->download($pdfUrl, false);
                    }
                }

                return null;
            }
            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->info('Téléchargement PDF échoué : '.$e->getMessage(), ['url' => $url]);

            return null;
        }

        if ('' === $content || \strlen($content) > self::MAX_PDF_BYTES || !str_starts_with($content, '%PDF')) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'oa_pdf_');
        if (false === $tmp) {
            return null;
        }
        file_put_contents($tmp, $content);

        return $tmp;
    }

    /**
     * Extrait l'URL du PDF déclarée par la page via la méta Highwire/Google Scholar
     * `citation_pdf_url` (ordre des attributs name/content indifférent). Renvoie une
     * URL absolue ou null. C'est le standard exposé par Elsevier, Springer, Wiley,
     * PMC, etc. — souvent renseigné même quand OpenAlex n'a pas le pdf_url.
     */
    private function extractCitationPdfUrl(string $html, string $base): ?string
    {
        if (preg_match('/<meta[^>]+name=["\']citation_pdf_url["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']citation_pdf_url["\']/i', $html, $m)) {
            $url = trim(html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5));

            return '' !== $url ? $this->resolveUrl($base, $url) : null;
        }

        return null;
    }

    /** Résout une URL (absolue ou relative) par rapport à l'URL de base. */
    private function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $scheme = $b['scheme'] ?? 'https';
        $host = $b['host'] ?? '';
        $port = isset($b['port']) ? ':'.$b['port'] : '';
        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$port.$location;
        }
        $path = $b['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $scheme.'://'.$host.$port.$dir.$location;
    }

    /** Extrait le texte via pdftotext (poppler), lancé sans shell (proc_open + tableau d'arguments). */
    private function extractText(string $pdfPath): ?string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['pdftotext', '-q', '-enc', 'UTF-8', '-nopgbrk', $pdfPath, '-'], $descriptors, $pipes);
        if (!\is_resource($process)) {
            return null;
        }
        $text = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if (0 !== $code || '' === $text) {
            return null;
        }
        // Normalise les espaces et coupures de mots en fin de ligne.
        $text = preg_replace('/-\n/', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Découpe en fragments d'environ CHUNK_CHARS caractères, sur des frontières de
     * phrases quand c'est possible.
     *
     * @return list<string>
     */
    private function chunk(string $text): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $pos = 0;
        while ($pos < $length && \count($chunks) < self::MAX_CHUNKS) {
            $slice = mb_substr($text, $pos, self::CHUNK_CHARS);
            // Tente de couper à la dernière fin de phrase du fragment.
            if ($pos + self::CHUNK_CHARS < $length) {
                $cut = max((int) mb_strrpos($slice, '. '), (int) mb_strrpos($slice, '! '), (int) mb_strrpos($slice, '? '));
                if ($cut > self::CHUNK_CHARS * 0.5) {
                    $slice = mb_substr($slice, 0, $cut + 1);
                }
            }
            $slice = trim($slice);
            if ('' !== $slice) {
                $chunks[] = $slice;
            }
            $pos += max(1, mb_strlen($slice));
        }

        return $chunks;
    }
}
