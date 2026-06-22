<?php

declare(strict_types=1);

namespace App\Pdf;

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * PDF basé sur un GABARIT PDF (en-tête/charte déjà dessinés dans template.pdf,
 * importé en fond de chaque page via FPDI). On ne stampe que le contenu (zone de
 * texte définie par les marges) et le numéro de page.
 */
final class TemplatePdf extends Fpdi
{
    /** Identifiant de la page de gabarit importée. */
    private mixed $tpl = null;

    public function loadTemplate(string $path): void
    {
        $this->setSourceFile($path);
        $this->tpl = $this->importPage(1);
    }

    // Fond de page : le gabarit, sur toute la page (toutes les pages).
    public function Header() // @phpstan-ignore-line
    {
        if (null !== $this->tpl) {
            $this->useTemplate($this->tpl, 0, 0, $this->getPageWidth(), $this->getPageHeight());
        }
    }

    // Numéro de page (page courante) à la position demandée : X=534pt, Y=818pt.
    public function Footer() // @phpstan-ignore-line
    {
        $this->SetXY(534, 818);
        $this->SetFont('dejavusans', '', 9);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(55, 10, 'page '.$this->PageNo(), 0, 0, 'L');
    }
}
