<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Oa\OaEnricher;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Résout l'accès ouvert (Unpaywall) des publications moissonnées qui ont un DOI
 * mais ne sont pas encore résolues (cf. Phase 1 §4, étape C).
 *
 *   bin/console harvester:resolve-oa --limit=500
 */
#[AsCommand(name: 'harvester:resolve-oa', description: 'Résout l\'accès ouvert légal (Unpaywall) des publications non résolues.')]
final class ResolveOaCommand extends Command
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly OaEnricher $enricher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de publications à résoudre', '200')
            ->addOption('sleep-ms', null, InputOption::VALUE_REQUIRED, 'Pause entre deux appels Unpaywall, en millisecondes', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $sleepUs = max(0, (int) $input->getOption('sleep-ms')) * 1000;

        $publications = $this->publications->findNeedingOaResolution($limit);
        if ([] === $publications) {
            $io->success('Aucune publication à résoudre.');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Résolution OA (Unpaywall) : %d publication(s)', \count($publications)));

        $resolved = 0;
        $errors = 0;
        $stored = 0;
        foreach ($publications as $publication) {
            try {
                if ($this->enricher->enrich($publication)) {
                    ++$resolved;
                    if ($publication->isFulltextStored()) {
                        ++$stored;
                    }
                }
            } catch (\Throwable $e) {
                ++$errors;
                $io->warning(\sprintf('Échec sur le DOI %s : %s', (string) $publication->getDoi(), $e->getMessage()));
            }

            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $this->em->flush();

        $io->definitionList(
            ['Traitées' => (string) \count($publications)],
            ['Résolues (OA trouvé)' => (string) $resolved],
            ['Full-text stockable' => (string) $stored],
            ['Erreurs' => (string) $errors],
        );

        return Command::SUCCESS;
    }
}
