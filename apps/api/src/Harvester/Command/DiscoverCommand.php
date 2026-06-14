<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Connector\ConnectorRegistry;
use App\Harvester\Dto\DiscoveryCursor;
use App\Harvester\HarvestRunner;
use App\Repository\IngestionJobRepository;
use App\Repository\SourceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lance la découverte/moisson pour une source (cf. Phase 1 §10).
 *
 *   bin/console harvester:discover openalex --since=2026-01-01 --max=500
 */
#[AsCommand(name: 'harvester:discover', description: 'Moissonne une source de publications en libre accès.')]
final class DiscoverCommand extends Command
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly ConnectorRegistry $connectors,
        private readonly IngestionJobRepository $jobs,
        private readonly HarvestRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Code de la source (ex. openalex)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Ne récupérer que les travaux mis à jour depuis cette date (YYYY-MM-DD)')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de travaux à traiter')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Reprendre depuis le dernier curseur enregistré pour cette source')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Publier chaque travail comme message (traitement asynchrone)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = (string) $input->getArgument('source');

        $source = $this->sources->findOneByCode($code);
        if (null === $source) {
            $io->error(\sprintf('Source inconnue : « %s ». Lancez d\'abord harvester:seed-sources.', $code));

            return Command::FAILURE;
        }
        if (!$this->connectors->has($code)) {
            $io->error(\sprintf('Aucun connecteur implémenté pour « %s » (sources disponibles : %s).', $code, implode(', ', $this->connectors->codes())));

            return Command::FAILURE;
        }

        $cursor = new DiscoveryCursor();

        if (null !== $input->getOption('since')) {
            $since = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('since'));
            if (false === $since) {
                $io->error('Date --since invalide (format attendu : YYYY-MM-DD).');

                return Command::FAILURE;
            }
            $cursor->since = $since;
        }

        if (null !== $input->getOption('max')) {
            $cursor->maxRecords = max(1, (int) $input->getOption('max'));
        }

        if ($input->getOption('resume')) {
            $cursor->cursor = $this->jobs->findLastEndCursor($source);
        }

        $async = (bool) $input->getOption('async');
        $io->title(\sprintf('Moisson : %s%s', $source->getName(), $async ? ' (asynchrone)' : ''));

        $job = $this->runner->run($source, $cursor, $async, [
            'since' => $cursor->since?->format('Y-m-d'),
            'max' => $cursor->maxRecords,
            'async' => $async,
        ]);

        $io->definitionList(
            ['Statut' => $job->getStatus()->value],
            ['Traités' => (string) $job->getProcessed()],
            ['Créés' => (string) $job->getCreated()],
            ['Erreurs' => (string) $job->getErrors()],
            ['Curseur final' => $job->getEndCursor() ?? '—'],
        );

        return $job->getErrors() > 0 && 0 === $job->getProcessed() ? Command::FAILURE : Command::SUCCESS;
    }
}
