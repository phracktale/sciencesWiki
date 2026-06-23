<?php

declare(strict_types=1);

namespace App\Analysis\Command;

use App\Analysis\AnalysisOptions;
use App\Analysis\AnalysisOrchestrator;
use App\Repository\TreeNodeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrateur d'analyse d'un nœud (cf. docs/spec-controverses-lacunes.md §7bis) :
 * enchaîne extraction des claims → détection des controverses (Phase A), puis les
 * étages pistes (Phase B) quand ils seront branchés. Habillage CLI/cron de
 * {@see AnalysisOrchestrator} ; l'UI passe par AnalyzeNodeMessage.
 *
 *   bin/console analysis:run --node=biochimie
 */
#[AsCommand(name: 'analysis:run', description: 'Lance l\'analyse complète (controverses & pistes) d\'un nœud.')]
final class AnalysisRunCommand extends Command
{
    public function __construct(
        private readonly AnalysisOrchestrator $orchestrator,
        private readonly TreeNodeRepository $nodes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('node', null, InputOption::VALUE_REQUIRED, 'Slug du nœud à analyser')
            ->addOption('reextract', null, InputOption::VALUE_NONE, 'Ré-extrait les claims des publications déjà traitées')
            ->addOption('openalex', null, InputOption::VALUE_NONE, 'Élargit la vérification croisée à OpenAlex (Phase B)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force le run même si le nœud est marqué « en cours »');
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

        $result = $this->orchestrator->run($node, new AnalysisOptions(
            reextract: (bool) $input->getOption('reextract'),
            force: (bool) $input->getOption('force'),
            openalex: (bool) $input->getOption('openalex'),
        ));

        $io->success(\sprintf(
            'Analyse de « %s » terminée : %d publication(s), %d claim(s), %d controverse(s), %d piste(s).',
            $slug,
            $result->publications,
            $result->claims,
            $result->controversies,
            $result->gaps,
        ));

        return Command::SUCCESS;
    }
}
