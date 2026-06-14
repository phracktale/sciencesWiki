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
    private const FLUSH_EVERY = 50;

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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        $publications = $this->publications->findNeedingEmbedding($limit);
        if ([] === $publications) {
            $io->success('Aucune publication à enrichir.');

            return Command::SUCCESS;
        }

        $done = 0;
        $errors = 0;
        foreach ($publications as $i => $publication) {
            try {
                $this->embedder->embed($publication);
                ++$done;
            } catch (\Throwable $e) {
                ++$errors;
                $io->warning(\sprintf('Échec embedding publication #%d : %s', (int) $publication->getId(), $e->getMessage()));
            }

            if (0 === ($i + 1) % self::FLUSH_EVERY) {
                $this->em->flush();
            }
        }
        $this->em->flush();

        $io->success(\sprintf('Embeddings calculés : %d (erreurs : %d).', $done, $errors));

        return Command::SUCCESS;
    }
}
