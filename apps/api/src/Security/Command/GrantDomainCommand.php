<?php

declare(strict_types=1);

namespace App\Security\Command;

use App\Entity\DomainExpertise;
use App\Repository\TreeNodeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rattache un membre (comité/relecteur) à un nœud : son périmètre de validation
 * (cf. spec §9.4 — comité élargi par domaine de compétence).
 *
 *   bin/console app:user:grant-domain expert@labo.fr computer-science
 */
#[AsCommand(name: 'app:user:grant-domain', description: 'Donne à un membre la compétence de validation sur un nœud.')]
final class GrantDomainCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail du membre')
            ->addArgument('node', InputArgument::REQUIRED, 'Slug du nœud (domaine de compétence)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->users->findOneByEmail((string) $input->getArgument('email'));
        if (null === $user) {
            $io->error('Utilisateur introuvable.');

            return Command::FAILURE;
        }

        $node = $this->nodes->findOneBy(['slug' => (string) $input->getArgument('node')]);
        if (null === $node) {
            $io->error('Nœud introuvable.');

            return Command::FAILURE;
        }

        if ($user->hasExpertiseOn($node)) {
            $io->info('Compétence déjà accordée.');

            return Command::SUCCESS;
        }

        $user->addExpertise(new DomainExpertise($node));
        $this->em->flush();

        $io->success(\sprintf('%s est désormais compétent sur « %s ».', $user->getDisplayName(), $node->getLabel()));

        return Command::SUCCESS;
    }
}
