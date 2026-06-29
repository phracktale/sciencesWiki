<?php

declare(strict_types=1);

namespace App\Analysis\Command;

use App\Analysis\Axis\AxisAppraiser;
use App\Repository\TreeNodeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Évalue par la grille AXIS les publications transversales d'un nœud (LLM, cf.
 * docs/spec-axis-articles.md). Gros consommateur LLM (20 items/article) : traité
 * par lots avec flush périodique dans {@see AxisAppraiser}.
 *
 * Outil de debug/backfill : en exploitation, c'est l'orchestrateur (`analysis:run`)
 * qui enchaîne cette étape.
 *
 *   bin/console analysis:appraise-axis --node=epidemiologie --reappraise
 */
#[AsCommand(name: 'analysis:appraise-axis', description: 'Évalue (AXIS) les études transversales d\'un nœud (LLM).')]
final class AppraiseAxisCommand extends Command
{
    public function __construct(
        private readonly AxisAppraiser $appraiser,
        private readonly TreeNodeRepository $nodes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('node', null, InputOption::VALUE_REQUIRED, 'Slug du nœud à traiter')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de publications', '1000')
            ->addOption('reappraise', null, InputOption::VALUE_NONE, 'Ré-évalue même les publications déjà traitées');
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

        $result = $this->appraiser->appraiseForNode(
            $node,
            max(1, (int) $input->getOption('limit')),
            (bool) $input->getOption('reappraise'),
        );

        $io->success(\sprintf(
            '%d publication(s) traitée(s), %d évaluation(s), dont %d étude(s) transversale(s).',
            $result['publications'],
            $result['appraised'],
            $result['applicable'],
        ));

        return Command::SUCCESS;
    }
}
