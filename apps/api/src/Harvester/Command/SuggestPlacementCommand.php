<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Ai\PlacementSuggester;
use App\Repository\PublicationRepository;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Propose le placement des publications enrichies dans l'arbre (kNN cosinus).
 * Les suggestions sont *non décisionnelles* : un humain valide (cf. spec §6.3).
 *
 *   bin/console harvester:suggest-placement --limit=500 -k 3
 */
#[AsCommand(name: 'harvester:suggest-placement', description: 'Propose le placement des publications dans l\'arbre (kNN).')]
final class SuggestPlacementCommand extends Command
{
    private const FLUSH_EVERY = 50;

    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly TreeNodeRepository $nodes,
        private readonly PlacementSuggester $suggester,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de publications à traiter', '500')
            ->addOption('neighbors', 'k', InputOption::VALUE_REQUIRED, 'Nombre de nœuds candidats par publication', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (0 === $this->nodes->countWithEmbedding()) {
            $io->error('Arbre vide (aucun nœud avec embedding). Lancez d\'abord harvester:seed-tree.');

            return Command::FAILURE;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $k = max(1, (int) $input->getOption('neighbors'));

        $publications = $this->publications->findNeedingPlacement($limit);
        if ([] === $publications) {
            $io->success('Aucune publication à placer.');

            return Command::SUCCESS;
        }

        $suggestions = 0;
        foreach ($publications as $i => $publication) {
            $suggestions += $this->suggester->suggest($publication, $k);

            if (0 === ($i + 1) % self::FLUSH_EVERY) {
                $this->em->flush();
            }
        }
        $this->em->flush();

        $io->success(\sprintf('%d publication(s) placée(s) en validation, %d suggestion(s) créée(s).', \count($publications), $suggestions));

        return Command::SUCCESS;
    }
}
