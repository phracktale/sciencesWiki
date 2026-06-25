<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Ai\PublicationEmbedder;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Calcule les embeddings des publications qui n'en ont pas encore
 * (cf. Phase 1 §4, étape F).
 *
 *   bin/console harvester:embed --limit=500
 */
#[AsCommand(name: 'harvester:embed', description: 'Calcule l\'embedding des publications non encore enrichies.')]
final class EmbedCommand extends Command
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly PublicationEmbedder $embedder,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de publications à enrichir', '500');
        // Taille de lot envoyée au service d'embeddings (POST /embed-batch) : un seul
        // appel encode N textes d'un coup → bien plus de débit que du 1-par-1.
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Nombre de textes par appel /embed-batch', '64');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $batch = max(1, (int) $input->getOption('batch'));

        $publications = $this->publications->findNeedingEmbedding($limit);
        if ([] === $publications) {
            $io->success('Aucune publication à enrichir.');

            return Command::SUCCESS;
        }

        $done = 0;
        $errors = 0;
        // Traitement PAR LOTS : un appel /embed-batch encode tout le lot en une passe
        // (vectorisé) — bien plus rapide que des appels unitaires.
        foreach (array_chunk($publications, $batch) as $chunk) {
            try {
                $this->embedder->embedMany($chunk);
                $done += \count($chunk);
            } catch (\Throwable $e) {
                $errors += \count($chunk);
                $io->warning(\sprintf('Échec embedding d\'un lot de %d : %s', \count($chunk), $e->getMessage()));
            }
            $this->em->flush();
        }

        $io->success(\sprintf('Embeddings calculés : %d (erreurs : %d).', $done, $errors));

        return Command::SUCCESS;
    }
}
