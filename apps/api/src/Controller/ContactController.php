<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réception des messages du formulaire de contact public (surface marketing CRT).
 * Envoie l'e-mail à l'équipe (best-effort, silencieux si MAILER_DSN=null) et journalise.
 * Anti-spam : pot de miel (champ « website ») + validation de l'e-mail. Pas de
 * stockage en base (pas d'entité dédiée) : tout passe par le mail + le journal d'activité.
 */
final class ContactController
{
    /** @var list<string> */
    private const SUBJECTS = ['question', 'signalement', 'presse', 'acces', 'don', 'autre'];

    public function __construct(
        private readonly \App\Service\ActivityLogger $activity,
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail = 'contact@scienceswiki.eu',
    ) {
    }

    #[Route('/api/contact', name: 'api_contact', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $d */
        $d = json_decode($request->getContent() ?: '[]', true) ?? [];

        // Pot de miel : un humain laisse ce champ vide. Rempli → on simule un succès
        // (ne pas renseigner le bot) sans rien faire.
        if ('' !== trim((string) ($d['website'] ?? ''))) {
            return new JsonResponse(['ok' => true, 'message' => 'message reçu, 5/5.'], Response::HTTP_CREATED);
        }

        $email = trim((string) ($d['email'] ?? ''));
        $message = trim((string) ($d['message'] ?? ''));
        $name = trim((string) ($d['name'] ?? ''));
        $subject = (string) ($d['subject'] ?? 'autre');
        if (!\in_array($subject, self::SUBJECTS, true)) {
            $subject = 'autre';
        }

        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Adresse e-mail invalide.'], 422);
        }
        if ('' === $message) {
            return new JsonResponse(['error' => 'Le message est vide.'], 422);
        }

        $name = mb_substr($name, 0, 120);
        $email = mb_substr($email, 0, 180);
        $message = mb_substr($message, 0, 5000);
        $ip = $request->getClientIp() ?? '0.0.0.0';

        $body = "Nouveau message de contact SciencesWiki\n\n"
            ."Objet ... $subject\n"
            ."Nom ..... ".('' !== $name ? $name : '(non renseigné)')."\n"
            ."E-mail .. $email\n"
            ."IP ...... $ip\n\n"
            ."Message :\n$message\n";

        $sent = false;
        try {
            $this->mailer->send((new Email())
                ->from($this->contactEmail)
                ->to($this->contactEmail)
                ->replyTo($email)
                ->subject('[Contact] '.$subject.('' !== $name ? ' — '.$name : ''))
                ->text($body));
            $sent = true;
        } catch (\Throwable) {
            // Best-effort : on journalise quand même pour ne rien perdre.
        }

        $this->activity->log('contact', 'message', '' !== $name ? $name : $email, \sprintf('Message « %s » reçu.', $subject), [
            'email' => $email,
            'subject' => $subject,
            'mailed' => $sent,
            'message' => mb_substr($message, 0, 500),
        ], $ip);

        return new JsonResponse(['ok' => true, 'message' => 'message reçu, 5/5.'], Response::HTTP_CREATED);
    }
}
