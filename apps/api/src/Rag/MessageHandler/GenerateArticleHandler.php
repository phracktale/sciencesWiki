<?php

declare(strict_types=1);

namespace App\Rag\MessageHandler;

use App\Enum\AnswerType;
use App\Rag\AnswerDrafter;
use App\Rag\Message\GenerateArticleMessage;
use App\Repository\QuestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Rédige l'article de façon asynchrone (pipeline 2 appels via AnswerDrafter::draft), puis,
 * si un e-mail a été fourni, notifie le demandeur avec le lien de l'article, le lien PDF et
 * le PDF en pièce jointe (récupéré à la volée depuis la route publique). Best-effort :
 * l'échec de l'e-mail n'invalide pas la génération.
 */
#[AsMessageHandler]
final class GenerateArticleHandler
{
    /** URL publique (liens + récupération du PDF à joindre). */
    private const BASE_URL = 'https://scienceswiki.eu';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly AnswerDrafter $drafter,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $fromEmail = 'contact@scienceswiki.eu',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(GenerateArticleMessage $message): void
    {
        $question = $this->questions->find($message->questionId);
        if (null === $question) {
            return;
        }

        // Rédaction (2 appels LLM) + persistance de la réponse.
        $answer = $this->drafter->draft($question, AnswerType::Free);
        $this->em->flush();
        $answerId = (int) $answer->getId();

        $email = trim((string) $message->notifyEmail);
        if ('' === $email) {
            return;
        }

        $title = $question->getTitle() ?? $question->getText();
        $articleUrl = self::BASE_URL.'/fr/q/'.$answerId;
        $pdfUrl = $articleUrl.'/pdf';

        $mail = (new Email())
            ->from($this->fromEmail)
            ->to($email)
            ->subject('Votre article SciencesWiki est prêt — '.mb_substr($title, 0, 80))
            ->text(
                "Bonjour,\n\n".
                "Votre article de vulgarisation « ".$title." » vient d'être rédigé sur SciencesWiki.\n\n".
                "Le lire en ligne : ".$articleUrl."\n".
                "Version PDF : ".$pdfUrl."\n\n".
                "Cet article a été généré automatiquement par IA à partir des sources citées ; ".
                "il est à vérifier auprès des sources primaires.\n\n".
                "— L'équipe SciencesWiki"
            )
            ->html(
                '<p>Bonjour,</p>'.
                '<p>Votre article de vulgarisation « <strong>'.htmlspecialchars($title, \ENT_QUOTES).'</strong> » '.
                'vient d\'être rédigé sur SciencesWiki.</p>'.
                '<p><a href="'.$articleUrl.'">Lire l\'article en ligne</a> &nbsp;·&nbsp; '.
                '<a href="'.$pdfUrl.'">Version PDF</a></p>'.
                '<p style="color:#555;font-size:13px">Article généré automatiquement par IA à partir des sources '.
                'citées ; à vérifier auprès des sources primaires.</p>'.
                '<p>— L\'équipe SciencesWiki</p>'
            );

        // Pièce jointe : le PDF public généré à la volée (best-effort).
        try {
            $pdf = $this->httpClient->request('GET', $pdfUrl, ['timeout' => 90])->getContent();
            $mail->attach($pdf, 'article-'.$answerId.'.pdf', 'application/pdf');
        } catch (\Throwable $e) {
            $this->logger->warning('PDF de l\'article non joint à l\'e-mail', ['answer' => $answerId, 'error' => $e->getMessage()]);
        }

        try {
            $this->mailer->send($mail);
        } catch (\Throwable $e) {
            $this->logger->warning('E-mail de notification d\'article non envoyé', ['answer' => $answerId, 'error' => $e->getMessage()]);
        }
    }
}
