<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

use App\Entity\Publication;
use Doctrine\DBAL\Connection;
use Pgvector\Vector;
use Psr\Log\LoggerInterface;
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

    public function __construct(
        EmbeddingClientFactory $factory,
        private readonly HttpClientInterface $httpClient,
        private readonly Connection $conn,
        private readonly LoggerInterface $logger,
    ) {
        $this->embedder = $factory->create();
    }

    /**
     * Tente d'ingérer le texte intégral d'une publication OA. Retourne le nombre
     * de fragments créés (0 si ignoré/échec).
     */
    public function ingest(Publication $publication): int
    {
        $id = $publication->getId();
        $url = $publication->getOaUrl();
        if (null === $id || null === $url || '' === $url) {
            return 0;
        }

        // On marque la tentative tout de suite pour ne pas réessayer en boucle.
        $this->conn->executeStatement('UPDATE publication SET fulltext_fetched_at = now() WHERE id = :id', ['id' => $id]);

        try {
            $pdf = $this->download($url);
            if (null === $pdf) {
                return 0;
            }
            $text = $this->extractText($pdf);
            @unlink($pdf);
            if (null === $text || mb_strlen($text) < 500) {
                return 0; // texte trop court / extraction infructueuse
            }

            $chunks = $this->chunk($text);
            if ([] === $chunks) {
                return 0;
            }
            // Embeddings des fragments PAR LOT (un seul appel au service ml/).
            $vectors = $this->embedder->embedBatch($chunks);
            $ord = 0;
            foreach ($chunks as $i => $chunk) {
                if (!isset($vectors[$i])) {
                    continue;
                }
                $this->conn->executeStatement(
                    'INSERT INTO publication_chunk (publication_id, ord, content, embedding)
                     VALUES (:p, :o, :c, CAST(:v AS vector))',
                    ['p' => $id, 'o' => $ord++, 'c' => $chunk, 'v' => (string) new Vector($vectors[$i])],
                );
            }

            return $ord;
        } catch (\Throwable $e) {
            $this->logger->info('Texte intégral non ingéré : '.$e->getMessage(), ['publication' => $id, 'url' => $url]);

            return 0;
        }
    }

    /**
     * Télécharge l'URL si c'est bien un PDF d'une taille raisonnable ; sinon null.
     * Si l'URL est une page HTML (page d'atterrissage), tente d'y découvrir le PDF
     * via la méta standard `citation_pdf_url` (présente chez la plupart des éditeurs
     * même quand OpenAlex n'a pas capté le pdf_url) puis le télécharge — une seule
     * fois ($allowDiscovery), pour éviter les boucles. Les paywalls renvoient une
     * page de login (non %PDF) → naturellement rejetés.
     */
    private function download(string $url, bool $allowDiscovery = true): ?string
    {
        // Anti-SSRF : on suit les redirections manuellement et on revalide CHAQUE
        // saut (schéma http/https + IP publique uniquement). oaUrl provient de
        // métadonnées externes : on ne doit jamais atteindre un service interne.
        $current = $url;
        $response = null;
        for ($hop = 0; $hop < 5; ++$hop) {
            $parts = parse_url($current);
            $scheme = strtolower($parts['scheme'] ?? '');
            $host = $parts['host'] ?? '';
            if (!\in_array($scheme, ['http', 'https'], true) || '' === $host) {
                return null;
            }
            $host = trim($host, '[]'); // littéral IPv6 éventuel
            $pinnedIp = $this->validatedIp($host);
            if (null === $pinnedIp) {
                return null; // hôte non résolu OU IP privée/réservée → on refuse
            }
            $options = [
                'timeout' => 30,
                'max_redirects' => 0,
                'headers' => ['Accept' => 'application/pdf,*/*'],
            ];
            // Épingle la connexion à l'IP validée (anti DNS-rebinding) ; le client
            // garde le nom d'hôte pour TLS/SNI/Host. Inutile si l'hôte est déjà une IP.
            if (!filter_var($host, \FILTER_VALIDATE_IP)) {
                $options['resolve'] = [$host => $pinnedIp];
            }
            $response = $this->httpClient->request('GET', $current, $options);
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $location = $response->getHeaders(false)['location'][0] ?? null;
                if (null === $location) {
                    return null;
                }
                // Résout une éventuelle URL relative par rapport à l'URL courante.
                $current = $this->resolveUrl($current, $location);
                continue;
            }
            break;
        }
        if (null === $response || 200 !== $response->getStatusCode()) {
            return null;
        }
        $contentType = strtolower($response->getHeaders(false)['content-type'][0] ?? '');
        $isPdf = str_contains($contentType, 'pdf') || str_ends_with(strtolower(parse_url($current, \PHP_URL_PATH) ?? ''), '.pdf');
        if (!$isPdf) {
            // Page HTML : on tente d'y découvrir le PDF (citation_pdf_url) une fois.
            if ($allowDiscovery && str_contains($contentType, 'html')) {
                $html = mb_substr((string) $response->getContent(false), 0, 600_000);
                $pdfUrl = $this->extractCitationPdfUrl($html, $current);
                if (null !== $pdfUrl && $pdfUrl !== $current) {
                    return $this->download($pdfUrl, false);
                }
            }

            return null; // page HTML sans PDF découvrable
        }

        $content = $response->getContent();
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
     * Résout l'hôte (A + AAAA), valide que TOUTES les IP sont publiques, et renvoie
     * UNE IP validée à épingler. Renvoie null si l'hôte ne résout pas ou si une
     * IP est privée/réservée (anti-SSRF, échec fermé). Valider l'ensemble puis
     * épingler élimine le DNS rebinding et le contournement par IPv6.
     */
    private function validatedIp(string $host): ?string
    {
        // Hôte déjà littéral (IPv4/IPv6) : on le valide directement.
        if (filter_var($host, \FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? $host : null;
        }

        $ips = [];
        foreach (@dns_get_record($host, \DNS_A) ?: [] as $r) {
            if (isset($r['ip'])) {
                $ips[] = $r['ip'];
            }
        }
        foreach (@dns_get_record($host, \DNS_AAAA) ?: [] as $r) {
            if (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        // Repli IPv4 si dns_get_record n'a rien renvoyé (chaînes CNAME, etc.).
        if ([] === $ips) {
            $ips = gethostbynamel($host) ?: [];
        }
        if ([] === $ips) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return null; // une seule IP privée/réservée suffit à tout refuser
            }
        }

        return $ips[0];
    }

    /** IP publique uniquement (rejette 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7…). */
    private function isPublicIp(string $ip): bool
    {
        return false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
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
