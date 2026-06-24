<?php

declare(strict_types=1);

namespace App\Rag;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Promeut les marqueurs « [réf. nécessaire: mots-clés] » (posés par
 * FaithfulnessChecker) en RÉFÉRENCES EXTERNES Wikipédia, lorsqu'un article réel
 * existe. Garde-fou central anti-hallucination : on ne fabrique JAMAIS d'URL — on
 * résout les mots-clés contre la VRAIE API Wikipédia (opensearch) et on ne lie que
 * si un article existe. Sinon, le marqueur redevient un simple « [réf. nécessaire] »
 * (à charge de l'éditeur de trouver une source).
 *
 * Le lien produit pointe vers fr.wikipedia.org → la convention visuelle (mini-logo
 * Wikipédia) s'applique automatiquement côté front.
 */
final class WikipediaLinker
{
    private const ENDPOINT = 'https://fr.wikipedia.org/w/api.php';

    /** @var array<string, ?string> cache de résolution (terme normalisé → URL|null) */
    private array $cache = [];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /**
     * Remplace chaque « [réf. nécessaire: mots-clés] » par un lien Wikipédia réel si
     * trouvé, sinon par « [réf. nécessaire] ». Renvoie le texte inchangé hors marqueurs.
     */
    public function resolve(string $text): string
    {
        if (!str_contains($text, 'réf. nécessaire')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\[réf\.\s*nécessaire(?:\s*:\s*([^\]]+))?\]/u',
            function (array $m): string {
                $concept = trim($m[1] ?? '');
                if ('' === $concept) {
                    return '[réf. nécessaire]';
                }
                $url = $this->lookup($concept);
                if (null === $url) {
                    return '[réf. nécessaire]';
                }

                return \sprintf('[Wikipédia : %s](%s)', $this->titleFromUrl($url) ?? $concept, $url);
            },
            $text,
        );
    }

    /** URL de l'article Wikipédia francophone correspondant, ou null si aucun. */
    private function lookup(string $term): ?string
    {
        $key = mb_strtolower($term);
        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            // opensearch renvoie [terme, [titres], [descriptions], [URLs]].
            $data = $this->http->request('GET', self::ENDPOINT, [
                'query' => ['action' => 'opensearch', 'search' => $term, 'limit' => 1, 'namespace' => 0, 'format' => 'json'],
                'timeout' => 5,
                // Wikipédia EXIGE un User-Agent identifiable (sinon 403).
                'headers' => ['User-Agent' => 'SciencesWiki/1.0 (https://scienceswiki.eu; contact@scienceswiki.eu)'],
            ])->toArray(false);

            $urls = $data[3] ?? [];
            $url = \is_array($urls) && isset($urls[0]) && '' !== $urls[0] ? (string) $urls[0] : null;

            return $this->cache[$key] = $url;
        } catch (\Throwable) {
            return $this->cache[$key] = null;
        }
    }

    /** « …/wiki/Compilation_à_la_volée » → « Compilation à la volée ». */
    private function titleFromUrl(string $url): ?string
    {
        if (preg_match('#/wiki/(.+)$#', $url, $m)) {
            return str_replace('_', ' ', urldecode($m[1]));
        }

        return null;
    }
}
