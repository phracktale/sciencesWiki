<?php

declare(strict_types=1);

namespace App\Analysis\Command;

use App\Analysis\Controversy\ControversyDetector;
use App\Repository\TreeNodeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * (Re)détecte les controverses d'un nœud à partir des claims déjà extraits
 * (cf. docs/spec-controverses-lacunes.md §6.1). Debug/backfill : en exploitation,
 * c'est l'orchestrateur (`analysis:run`) qui enchaîne cette étape.
 *
 *   bin/console analysis:detect-controversies --node=biochimie --theta=0.15
 */
#[AsCommand(name: 'analysis:detect-controversies', description: 'Détecte les controverses d\'un nœud (clusters de claims opposés).')]
final class DetectControversiesCommand extends Command
{
    public function __construct(
        private readonly ControversyDetector $detector,
        private readonly TreeNodeRepository $nodes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('node', null, InputOption::VALUE_REQUIRED, 'Slug du nœud à traiter')
            ->addOption('theta', null, InputOption::VALUE_REQUIRED, 'Distance cosinus de fusion des axes', (string) ControversyDetector::DEFAULT_THETA);
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

        $controversies = $this->detector->detect($node, (float) $input->getOption('theta'));

        $io->success(\sprintf('%d controverse(s) détectée(s) sur « %s ».', \count($controversies), $slug));

        return Command::SUCCESS;
    }
}
