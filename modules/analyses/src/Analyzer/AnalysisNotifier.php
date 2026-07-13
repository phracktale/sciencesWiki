<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Entity\Assessment;
use Analyses\Pdf\PdfRenderer;
use Analyses\Repository\AssessmentCriterionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Notification de fin d'analyse : e-mail au demandeur avec le titre, le DOI, le chemin
 * arborescent, le plan/résultat, la synthèse, un lien vers le classeur ET le rapport PDF
 * en pièce jointe. Best-effort (no-op si MAILER_DSN=null ou e-mail invalide).
 */
final class AnalysisNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly PdfRenderer $pdf,
        private readonly AssessmentCriterionRepository $criteria,
        #[Autowire(env: 'default::ANALYS_MAIL_FROM')]
        private readonly ?string $mailFrom = null,
        #[Autowire(env: 'default::MODULE_BASE_URL')]
        private readonly ?string $baseUrl = null,
    ) {
    }

    public function notify(Assessment $a): void
    {
        $to = $a->getRequestedBy();
        if (null === $to || !filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $title = $a->getDocumentTitle() ?? $a->getDocumentRef();
        $link = null !== $this->baseUrl && '' !== $this->baseUrl
            ? rtrim($this->baseUrl, '/').'?id='.$a->getId()
            : null;

        $lines = [
            'Bonjour,',
            '',
            'Votre analyse méthodologique est terminée.',
            '',
            'Article : '.$title,
            'DOI : '.($a->getDocumentDoi() ?? '—'),
            'Emplacement : '.($a->treePathLabel() ?? '(non classé)'),
            'Plan d’étude : '.($a->getPrimaryDesign() ?? 'indéterminé'),
            'Statut : '.$this->statusLabel($a),
        ];
        if (null !== $a->getSummary()) {
            $lines[] = '';
            $lines[] = 'Synthèse : '.$a->getSummary();
        }
        $lines[] = '';
        if (null !== $link) {
            $lines[] = 'Consulter dans votre classeur : '.$link;
        }
        $lines[] = 'Le rapport PDF est joint à cet e-mail.';
        $lines[] = '';
        $lines[] = '— SciencesWiki';

        $email = (new Email())
            ->from($this->mailFrom ?: 'noreply@scienceswiki.eu')
            ->to($to)
            ->subject('Analyse terminée — '.mb_substr($title, 0, 120))
            ->text(implode("\n", $lines));

        try {
            $pdf = $this->pdf->render($a, $this->criteria->findForAssessment($a->getId()));
            $email->attach($pdf, 'analyse-'.$a->getId().'.pdf', 'application/pdf');
        } catch (\Throwable) {
            // Sans PDF, on envoie quand même l'e-mail informatif.
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface) {
            // Notification best-effort.
        }
    }

    private function statusLabel(Assessment $a): string
    {
        return match ($a->getStatus()) {
            'completed' => 'Terminée',
            'human_review_required' => 'Terminée — validation humaine recommandée',
            'validated' => 'Validée',
            'failed' => 'Échec',
            default => $a->getStatus(),
        };
    }
}
