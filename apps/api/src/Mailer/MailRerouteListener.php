<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Service\SettingsService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Réacheminement global des e-mails : si le switch BO est activé, TOUS les e-mails
 * sortants sont livrés à l'adresse paramétrée, quels que soient leurs destinataires
 * (utile en tests / pré-prod / supervision Brevo). C'est l'enveloppe SMTP qui est
 * réécrite (RCPT TO) → garantit la redirection même pour Cc/Cci. Les destinataires
 * d'origine sont conservés dans un en-tête X-Original-Recipients.
 */
#[AsEventListener(event: MessageEvent::class)]
final class MailRerouteListener
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function __invoke(MessageEvent $event): void
    {
        if (!$this->settings->mailRerouteEnabled()) {
            return;
        }
        $to = $this->settings->mailRerouteTo();
        if ('' === $to || false === filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // L'enveloppe pilote la livraison réelle : on n'y met QUE l'adresse cible.
        $event->getEnvelope()->setRecipients([new Address($to)]);

        $message = $event->getMessage();
        if ($message instanceof Email) {
            $orig = [];
            foreach ([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()] as $addr) {
                $orig[] = $addr->toString();
            }
            if ([] !== $orig) {
                $message->getHeaders()->addTextHeader('X-Original-Recipients', implode(', ', $orig));
            }
            $message->to(new Address($to));
        }
    }
}
