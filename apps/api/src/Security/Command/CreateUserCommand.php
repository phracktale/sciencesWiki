<?php

declare(strict_types=1);

namespace App\Security\Command;

use App\Entity\User;
use App\Enum\ProfileType;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée (ou met à jour) un compte avec ses rôles (cf. spec §4) :
 * ROLE_ADMIN, ROLE_MODERATEUR, ROLE_COMITE, ROLE_REDACTEUR.
 *
 *   bin/console app:user:create admin@scienceswiki.org --role=ROLE_ADMIN --verified
 */
#[AsCommand(name: 'app:user:create', description: 'Crée ou met à jour un compte (admin/modérateur/comité/rédacteur).')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'Adresse e-mail (identifiant)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe (généré si absent)')
            ->addOption('real-name', null, InputOption::VALUE_REQUIRED, 'Nom réel', '')
            ->addOption('pseudo', null, InputOption::VALUE_REQUIRED, 'Pseudonyme public')
            ->addOption('role', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Rôle(s) parmi '.implode(', ', UserRole::values()), [])
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Profil : scientifique | vulgarisateur | contributeur', 'contributeur')
            ->addOption('orcid', null, InputOption::VALUE_REQUIRED, 'ORCID')
            ->addOption('affiliation', null, InputOption::VALUE_REQUIRED, 'Affiliation')
            ->addOption('verified', null, InputOption::VALUE_NONE, 'Marquer l\'identité comme vérifiée');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $roles = array_map('strtoupper', (array) $input->getOption('role'));
        $invalid = array_diff($roles, UserRole::values());
        if ([] !== $invalid) {
            $io->error('Rôle(s) invalide(s) : '.implode(', ', $invalid));

            return Command::FAILURE;
        }

        $profileType = ProfileType::tryFrom((string) $input->getOption('type'));
        if (null === $profileType) {
            $io->error('Type de profil invalide.');

            return Command::FAILURE;
        }

        $password = (string) ($input->getOption('password') ?? '');
        if ('' === $password) {
            $password = bin2hex(random_bytes(6));
            $io->note('Mot de passe généré : '.$password);
        }

        $user = $this->users->findOneByEmail($email);
        $isNew = null === $user;
        if ($isNew) {
            $user = new User($email, (string) $input->getOption('real-name') ?: $email);
            $this->em->persist($user);
        } elseif ('' !== (string) $input->getOption('real-name')) {
            $user->setRealName((string) $input->getOption('real-name'));
        }

        $user
            ->setRoles($roles)
            ->setProfileType($profileType)
            ->setPassword($this->hasher->hashPassword($user, $password));

        if (null !== $input->getOption('pseudo')) {
            $user->setPseudo((string) $input->getOption('pseudo'));
        }
        if (null !== $input->getOption('orcid')) {
            $user->setOrcid((string) $input->getOption('orcid'));
        }
        if (null !== $input->getOption('affiliation')) {
            $user->setAffiliation((string) $input->getOption('affiliation'));
        }
        if ($input->getOption('verified')) {
            $user->verifyIdentity('manuelle');
        }

        $this->em->flush();

        $io->success(\sprintf(
            '%s : %s (rôles : %s%s)',
            $isNew ? 'Compte créé' : 'Compte mis à jour',
            $email,
            implode(', ', $user->getRoles()),
            $user->isIdentityVerified() ? ', identité vérifiée' : '',
        ));

        return Command::SUCCESS;
    }
}
