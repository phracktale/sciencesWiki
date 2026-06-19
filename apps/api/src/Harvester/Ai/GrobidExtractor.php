<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Extraction structurée d'un PDF scientifique via un service GROBID auto-hébergé
 * (TEI). Renvoie des fragments « conscients des sections » (résumé + corps), prêts
 * à être vectorisés — bien plus propres que `pdftotext` (pas d'en-têtes/numéros de
 * ligne, découpage par section). Désactivé si GROBID_URL est vide.
 */
final class GrobidExtractor
{
    private const CHUNK_CHARS = 1500;
    private const TEI_NS = 'http://www.tei-c.org/ns/1.0';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'GROBID_URL')]
        private readonly string $grobidUrl = '',
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->grobidUrl);
    }

    /**
     * Envoie le PDF à GROBID, renvoie une liste de fragments (section + texte).
     * Liste vide si GROBID est indisponible ou le PDF inexploitable.
     *
     * @return list<string>
     */
    public function extract(string $pdfPath, int $maxChunks = 80): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $pdf = @file_get_contents($pdfPath);
        if (false === $pdf || '' === $pdf) {
            return [];
        }

        // Corps multipart/form-data construit à la main (pas de dépendance symfony/mime).
        $boundary = '----SciencesWikiGrobid'.bin2hex(random_bytes(8));
        $eol = "\r\n";
        $body = '--'.$boundary.$eol
            .'Content-Disposition: form-data; name="input"; filename="document.pdf"'.$eol
            .'Content-Type: application/pdf'.$eol.$eol
            .$pdf.$eol;
        foreach (['consolidateHeader' => '0', 'consolidateCitations' => '0', 'segmentSentences' => '0'] as $name => $value) {
            $body .= '--'.$boundary.$eol
                .'Content-Disposition: form-data; name="'.$name.'"'.$eol.$eol
                .$value.$eol;
        }
        $body .= '--'.$boundary.'--'.$eol;

        try {
            $response = $this->httpClient->request('POST', rtrim($this->grobidUrl, '/').'/api/processFulltextDocument', [
                'headers' => ['Content-Type' => 'multipart/form-data; boundary='.$boundary],
                'body' => $body,
                'timeout' => 180,
            ]);
            if (200 !== $response->getStatusCode()) {
                return [];
            }
            $tei = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->info('GROBID indisponible/échec : '.$e->getMessage());

            return [];
        }

        return $this->teiToChunks($tei, $maxChunks);
    }

    /**
     * Convertit le TEI en fragments : résumé + chaque section <div> (head + <p>),
     * découpés en ~1500 caractères sans franchir de frontière de section.
     *
     * @return list<string>
     */
    private function teiToChunks(string $tei, int $maxChunks): array
    {
        $doc = new \DOMDocument();
        if ('' === trim($tei) || !@$doc->loadXML($tei)) {
            return [];
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('tei', self::TEI_NS);

        /** @var list<array{0:string,1:string}> $sections [titre, texte] */
        $sections = [];

        $abstract = [];
        foreach ($xp->query('//tei:profileDesc/tei:abstract//tei:p') as $p) {
            $t = trim($p->textContent);
            if ('' !== $t) {
                $abstract[] = $t;
            }
        }
        if ([] !== $abstract) {
            $sections[] = ['Résumé', implode(' ', $abstract)];
        }

        foreach ($xp->query('//tei:text/tei:body/tei:div') as $div) {
            $heads = $xp->query('./tei:head', $div);
            $head = $heads->length > 0 ? trim($heads->item(0)->textContent) : '';
            $paras = [];
            foreach ($xp->query('./tei:p', $div) as $p) {
                $t = trim($p->textContent);
                if ('' !== $t) {
                    $paras[] = $t;
                }
            }
            if ([] !== $paras) {
                $sections[] = [$head, implode(' ', $paras)];
            }
        }

        $chunks = [];
        foreach ($sections as [$head, $text]) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
            $prefix = '' !== $head ? $head.' — ' : '';
            $len = mb_strlen($text);
            for ($i = 0; $i < $len; $i += self::CHUNK_CHARS) {
                if (\count($chunks) >= $maxChunks) {
                    return $chunks;
                }
                $piece = trim(mb_substr($text, $i, self::CHUNK_CHARS));
                if (mb_strlen($piece) > 40) {
                    $chunks[] = $prefix.$piece;
                }
            }
        }

        return $chunks;
    }
}
