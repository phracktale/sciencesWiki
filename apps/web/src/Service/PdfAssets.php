<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fournit les ressources embarquées dans les PDF (dompdf). Le logo est servi en
 * data-URI (base64) pour éviter tout accès fichier/réseau au moment du rendu.
 */
final class PdfAssets
{
    private ?string $logo = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** Chemin absolu du gabarit PDF (en-tête/charte) importé en fond de page. */
    public function templatePath(): string
    {
        return $this->projectDir.'/assets/pdf/template.pdf';
    }

    /** Logo SciencesWiki (SVG vectoriel) en data-URI base64, ou chaîne vide si absent. */
    public function logo(): string
    {
        if (null === $this->logo) {
            $path = $this->projectDir.'/public/logo.svg';
            $svg = is_readable($path) ? (string) file_get_contents($path) : '';
            // La racine SVG est en width/height="100%" → dompdf l'étirerait. On fige la
            // taille demandée (150×37.9 pt) ; preserveAspectRatio="none" remplit la boîte.
            $svg = str_replace('width="100%" height="100%"', 'width="150pt" height="37.9pt" preserveAspectRatio="none"', $svg);
            $this->logo = '' !== $svg ? 'data:image/svg+xml;base64,'.base64_encode($svg) : '';
        }

        return $this->logo;
    }
}
