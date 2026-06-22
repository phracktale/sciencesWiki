<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Test d'envoi e-mail (vérifie la configuration MAILER_DSN / Brevo).
 *
 *   bin/console app:mail:test destinataire@example.org
 */
#[AsCommand(name: 'app:mail:test', description: 'Envoie un e-mail de test (vérifie MAILER_DSN / Brevo).')]
final class MailTestCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $fromEmail = 'contact@scienceswiki.eu',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Adresse destinataire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string) $input->getArgument('to');

        try {
            $this->mailer->send((new Email())
                ->from($this->fromEmail)
                ->to($to)
                ->subject('Test SciencesWiki — envoi e-mail OK')
                ->text("Ceci est un e-mail de test de SciencesWiki.\nSi vous le recevez, la configuration Brevo/SMTP fonctionne.\n\nL'équipe SciencesWiki"));
        } catch (\Throwable $e) {
            $io->error('Échec de l\'envoi : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('E-mail de test envoyé à %s (expéditeur : %s).', $to, $this->fromEmail));

        return Command::SUCCESS;
    }
}
