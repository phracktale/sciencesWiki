<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre Twig `json_js` : sérialise une valeur en JSON sûr pour un contexte <script>.
 * Ajoute les flags JSON_HEX_* pour échapper < > & ' " → impossible de « casser » la
 * balise script via un </script> ou une apostrophe injectés dans la donnée (durcissement
 * XSS). À utiliser à la place de `|json_encode|raw` pour toute valeur inline dans du JS.
 *
 *   var X = {{ maValeur|json_js }};
 */
final class JsonJsExtension extends AbstractExtension
{
    private const FLAGS = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE;

    public function getFilters(): array
    {
        return [
            // is_safe html : la sortie est déjà échappée pour un contexte script,
            // pas besoin de |raw (et pas de double-échappement Twig).
            new TwigFilter('json_js', $this->encode(...), ['is_safe' => ['html']]),
        ];
    }

    public function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS) ?: 'null';
    }
}
