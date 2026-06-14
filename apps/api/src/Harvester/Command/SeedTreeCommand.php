<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Ai\OpenAlexTaxonomySeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Amorce l'arbre des connaissances depuis les concepts OpenAlex (cf. spec §7).
 *
 *   bin/console harvester:seed-tree --max-level=1
 */
#[AsCommand(name: 'harvester:seed-tree', description: 'Amorce l\'arbre des connaissances depuis la taxonomie OpenAlex.')]
final class SeedTreeCommand extends Command
{
    public function __construct(private readonly OpenAlexTaxonomySeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-level', null, InputOption::VALUE_REQUIRED, 'Niveau max : 0=domaines, 1=+champs, 2=+sous-champs, 3=+topics', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maxLevel = max(0, (int) $input->getOption('max-level'));

        $io->title(\sprintf('Amorçage de l\'arbre (taxonomie OpenAlex, niveaux 0..%d)', $maxLevel));
        $result = $this->seeder->seed($maxLevel);

        $io->success(\sprintf('Arbre amorcé : %d nœud(s), %d arête(s).', $result['nodes'], $result['edges']));

        return Command::SUCCESS;
    }
}
