<?php

declare(strict_types=1);

namespace Analyses\Pdf;

use Analyses\Entity\Assessment;
use Analyses\Entity\AssessmentCriterion;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Port SDK « pdf:render » : génère à la volée un PDF de l'évaluation (dompdf).
 * Rendu autonome (charte simple) ; l'intégration de la charte SciencesWiki complète
 * pourra se faire via un template dédié.
 */
final class PdfRenderer
{
    /**
     * @param list<AssessmentCriterion> $criteria
     */
    public function render(Assessment $a, array $criteria): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($a, $criteria), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param list<AssessmentCriterion> $criteria
     */
    private function html(Assessment $a, array $criteria): string
    {
        $fp = $a->getFingerprint() ?? [];
        $plan = $a->getPlan() ?? [];
        $rows = '';
        foreach ($criteria as $c) {
            $rows .= \sprintf(
                '<tr><td class="cid">%s</td><td class="ans %s">%s</td><td>%s<div class="an">%s</div></td><td class="cf">%s</td></tr>',
                $this->e($c->getCriterionId()),
                $this->e($this->answerClass($c->getAnswer())),
                $this->e($c->getAnswer()),
                $this->e($c->getQuestion()),
                $this->e($c->getAnalysis() ?? ''),
                $this->e($c->getConfidence() ?? ''),
            );
        }
        if ('' === $rows) {
            $rows = '<tr><td colspan="4" class="muted">Aucun critère (référentiel sans analyseur exécuté).</td></tr>';
        }

        $frameworks = implode(', ', array_map([$this, 'e'], $plan['primary_frameworks'] ?? []));
        $rob = implode(', ', array_map([$this, 'e'], $plan['risk_of_bias_tools'] ?? []));
        $banner = $a->isHumanReview()
            ? '<p class="warn">⚠️ Analyse générée par IA — validation humaine requise. Les réponses non ancrées sur une citation ont été rétrogradées.</p>'
            : '<p class="ok">Analyse générée par IA — ancrée sur les sources du texte intégral.</p>';

        return <<<HTML
            <html><head><meta charset="utf-8"><style>
              body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1e293b; }
              .band { background: #0f172a; color: #fff; padding: 14px 16px; }
              .band h1 { margin: 0; font-size: 16px; }
              .band .sub { font-size: 10px; opacity: .85; }
              .meta { margin: 12px 0; font-size: 10px; }
              .meta b { color: #0f172a; }
              .warn { background: #fef3c7; border: 1px solid #f59e0b; padding: 6px 8px; border-radius: 4px; }
              .ok { background: #dcfce7; border: 1px solid #16a34a; padding: 6px 8px; border-radius: 4px; }
              table { width: 100%; border-collapse: collapse; margin-top: 8px; }
              th, td { border: 1px solid #cbd5e1; padding: 4px 6px; vertical-align: top; text-align: left; }
              th { background: #f1f5f9; font-size: 9px; }
              td.cid { font-family: monospace; font-size: 8px; white-space: nowrap; }
              td.cf { text-align: center; }
              td.ans { font-weight: bold; text-align: center; text-transform: uppercase; font-size: 8px; }
              .ans.pos { color: #166534; } .ans.neg { color: #b91c1c; } .ans.neu { color: #92400e; }
              .an { color: #475569; font-size: 8px; margin-top: 2px; font-style: italic; }
              .muted { color: #94a3b8; text-align: center; }
              .foot { margin-top: 12px; font-size: 8px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 6px; }
            </style></head><body>
              <div class="band">
                <h1>Analyse méthodologique — SciencesWiki</h1>
                <div class="sub">Module Analyses (ANALYS) · résultat canonique</div>
              </div>
              {$banner}
              <div class="meta">
                <b>Publication :</b> {$this->e($a->getDocumentRef())}<br>
                <b>Plan d'étude :</b> {$this->e((string) ($fp['design_label'] ?? $a->getPrimaryDesign() ?? 'indéterminé'))}<br>
                <b>Référentiels :</b> {$frameworks}{$this->sep($rob)}{$rob}<br>
                <b>Statut :</b> {$this->e($a->getStatus())}
              </div>
              <table>
                <thead><tr><th>Critère</th><th>Réponse</th><th>Question / analyse</th><th>Conf.</th></tr></thead>
                <tbody>{$rows}</tbody>
              </table>
              <div class="foot">
                Modèle : {$this->e($a->getModel() ?? 'n/d')} · généré le {$a->getCreatedAt()->format('d/m/Y H:i')} ·
                identifiant {$this->e((string) $a->getId())}.
                Outil d'aide : ne remplace pas l'évaluation d'un expert.
              </div>
            </body></html>
            HTML;
    }

    private function answerClass(string $answer): string
    {
        return match ($answer) {
            'yes', 'low' => 'pos',
            'no', 'high' => 'neg',
            default => 'neu',
        };
    }

    private function sep(string $rob): string
    {
        return '' !== $rob ? ' · ' : '';
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
