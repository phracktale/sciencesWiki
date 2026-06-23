<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonction Twig `asset_v('/css/x.css')` : ajoute un suffixe de version basé sur la
 * date de modification du fichier (?v=<mtime>). L'URL ne change QUE quand le fichier
 * change → le cache navigateur est invalidé pile au bon moment (après un déploiement
 * qui touche le fichier), tout en restant pleinement caché le reste du temps.
 *
 * En conteneur, COPY préserve la mtime issue du checkout Git : un fichier inchangé
 * garde sa version, un fichier modifié en obtient une nouvelle.
 */
final class AssetExtension extends AbstractExtension
{
    /** @var array<string,string> cache des versions par chemin (une stat par requête) */
    private array $versions = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset_v', $this->versioned(...)),
        ];
    }

    /** Retourne le chemin suffixé de ?v=<mtime> (ou inchangé si le fichier est absent). */
    public function versioned(string $path): string
    {
        return $this->versions[$path] ??= $this->compute($path);
    }

    private function compute(string $path): string
    {
        $file = $this->projectDir.'/public/'.ltrim($path, '/');
        $mtime = @filemtime($file);
        if (false === $mtime) {
            return $path;
        }

        return $path.(str_contains($path, '?') ? '&' : '?').'v='.$mtime;
    }
}
