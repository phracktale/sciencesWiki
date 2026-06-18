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
            $ord = 0;
            foreach ($chunks as $chunk) {
                $vec = (string) new Vector($this->embedder->embed($chunk));
                $this->conn->executeStatement(
                    'INSERT INTO publication_chunk (publication_id, ord, content, embedding)
                     VALUES (:p, :o, :c, CAST(:v AS vector))',
                    ['p' => $id, 'o' => $ord++, 'c' => $chunk, 'v' => $vec],
                );
            }

            return $ord;
        } catch (\Throwable $e) {
            $this->logger->info('Texte intégral non ingéré : '.$e->getMessage(), ['publication' => $id, 'url' => $url]);

            return 0;
        }
    }

    /** Télécharge l'URL si c'est bien un PDF d'une taille raisonnable ; sinon null. */
    private function download(string $url): ?string
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'max_redirects' => 5,
            'headers' => ['Accept' => 'application/pdf,*/*'],
        ]);
        if (200 !== $response->getStatusCode()) {
            return null;
        }
        $contentType = strtolower($response->getHeaders(false)['content-type'][0] ?? '');
        $isPdf = str_contains($contentType, 'pdf') || str_ends_with(strtolower(parse_url($url, \PHP_URL_PATH) ?? ''), '.pdf');
        if (!$isPdf) {
            return null; // page HTML (landing page) et non PDF direct
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
