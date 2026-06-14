<?php

declare(strict_types=1);

namespace App\Rag\Command;

use App\Rag\AnswerValidator;
use App\Repository\AnswerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validation d'une réponse par le comité (cf. spec §8.4).
 *
 *   bin/console wiki:validate-answer --answer=12
 */
#[AsCommand(name: 'wiki:validate-answer', description: 'Valide (ou renvoie en relecture) une réponse.')]
final class ValidateAnswerCommand extends Command
{
    public function __construct(
        private readonly AnswerRepository $answers,
        private readonly UserRepository $users,
        private readonly AnswerValidator $validator,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('answer', null, InputOption::VALUE_REQUIRED, 'ID de la réponse')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'E-mail du membre du comité validant')
            ->addOption('reject', null, InputOption::VALUE_NONE, 'Renvoyer en relecture au lieu de valider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $answer = $this->answers->find((int) $input->getOption('answer'));
        if (null === $answer) {
            $io->error('Réponse introuvable.');

            return Command::FAILURE;
        }

        if ($input->getOption('reject')) {
            $this->validator->sendBackToReview($answer);
            $message = 'Réponse renvoyée en relecture.';
        } else {
            $reviewer = null !== $input->getOption('by')
                ? $this->users->findOneByEmail((string) $input->getOption('by'))
                : null;
            $this->validator->validate($answer, $reviewer);
            $message = 'Réponse validée par le comité (publiable, label « validé »).';
        }
        $this->em->flush();

        $io->success($message.' Statut : '.$answer->getValidationStatus()->value);

        return Command::SUCCESS;
    }
}
