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

    /** Date affichée au pied de page (jj/mm/aaaa). */
    private string $footerDate = '';

    public function loadTemplate(string $path): void
    {
        $this->setSourceFile($path);
        $this->tpl = $this->importPage(1);
    }

    public function setFooterDate(string $date): void
    {
        $this->footerDate = $date;
    }

    // Fond de page : le gabarit, sur toute la page (toutes les pages).
    public function Header() // @phpstan-ignore-line
    {
        if (null !== $this->tpl) {
            $this->useTemplate($this->tpl, 0, 0, $this->getPageWidth(), $this->getPageHeight());
        }
    }

    // Pied de page : date (X=118) + numéro de page courante (X=534), à Y=818pt.
    public function Footer() // @phpstan-ignore-line
    {
        $this->SetFont('dejavusans', '', 8.5);
        $this->SetTextColor(100, 116, 139); // #64748B
        if ('' !== $this->footerDate) {
            $this->SetXY(118, 818);
            $this->Cell(120, 10, $this->footerDate, 0, 0, 'L');
        }
        $this->SetXY(534, 818);
        $this->Cell(55, 10, 'page '.$this->PageNo(), 0, 0, 'L');
    }
}
