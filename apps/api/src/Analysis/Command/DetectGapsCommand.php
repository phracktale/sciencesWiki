<?php

declare(strict_types=1);

namespace App\Analysis\Command;

use App\Analysis\Gap\GapDetector;
use App\Repository\TreeNodeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * (Re)détecte les pistes inexplorées d'un nœud à partir des claims déjà extraits
 * (cf. docs/spec-controverses-lacunes.md §6.2–§6.4). Debug/backfill : en
 * exploitation, c'est l'orchestrateur (`analysis:run`) qui enchaîne cette étape.
 *
 *   bin/console analysis:detect-gaps --node=biochimie
 */
#[AsCommand(name: 'analysis:detect-gaps', description: 'Détecte les pistes inexplorées d\'un nœud (Swanson, cases creuses, lacunes auto-déclarées).')]
final class DetectGapsCommand extends Command
{
    public function __construct(
        private readonly GapDetector $detector,
        private readonly TreeNodeRepository $nodes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('node', null, InputOption::VALUE_REQUIRED, 'Slug du nœud à traiter');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug = (string) $input->getOption('node');
        if ('' === $slug) {
            $io->error('L\'option --node=<slug> est requise.');

            return Command::FAILURE;
        }
        $node = $this->nodes->findOneBy(['slug' => $slug]);
        if (null === $node) {
            $io->error(\sprintf('Nœud « %s » introuvable.', $slug));

            return Command::FAILURE;
        }

        $gaps = $this->detector->detect($node);

        $io->success(\sprintf('%d piste(s) détectée(s) sur « %s ».', \count($gaps), $slug));

        return Command::SUCCESS;
    }
}
