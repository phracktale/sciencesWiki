<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Message\HarvestRubric;
use App\Repository\IngestionJobRepository;
use App\Repository\TreeNodeRepository;
use App\Service\SettingsService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Moisson AUTOMATIQUE de toutes les rubriques, sans intervention manuelle : à
 * chaque passage (cron), enfile la moisson des rubriques pas encore « terminées »
 * — c'est-à-dire ni au plafond configuré, ni épuisées chez OpenAlex (processed ≥
 * total) — en commençant par les moins récemment moissonnées. Reprend là où le
 * curseur s'était arrêté ; les workers traitent en parallèle.
 *
 * Anti-doublon : ne ré-enfile pas une rubrique déjà en cours, déjà en file, ou
 * moissonnée très récemment (--min-age). Borné par passage (--limit) pour lisser.
 *
 *   bin/console app:harvest:auto --limit=40 --min-age=120
 */
#[AsCommand(name: 'app:harvest:auto', description: 'Enfile automatiquement la moisson des rubriques non terminées (sans clic).')]
final class AutoHarvestCommand extends Command
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly IngestionJobRepository $jobs,
        private readonly SettingsService $settings,
        private readonly MessageBusInterface $bus,
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de rubriques à enfiler par passage', '40');
        $this->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'Minutes minimales depuis la dernière moisson avant de ré-enfiler', '120');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $minAge = max(0, (int) $input->getOption('min-age'));
        $cap = $this->settings->harvestCapPerRubric();

        // Rubriques candidates : mappées à un concept, pas moissonnées trop récemment,
        // les moins récentes d'abord (jamais moissonnées en tête).
        $rows = $this->conn->executeQuery(
            'SELECT id, slug FROM tree_node
              WHERE openalex_concept_id IS NOT NULL
                AND (last_harvested_at IS NULL OR last_harvested_at < now() - make_interval(mins => :age))
              ORDER BY last_harvested_at ASC NULLS FIRST, id ASC',
            ['age' => $minAge],
        )->fetchAllAssociative();

        $enqueued = 0;
        $skippedDone = 0;
        $skippedBusy = 0;
        foreach ($rows as $row) {
            if ($enqueued >= $limit) {
                break;
            }
            $id = (int) $row['id'];
            $slug = (string) $row['slug'];

            // « Terminée » : au plafond configuré, ou épuisée chez OpenAlex.
            $processed = $this->jobs->sumProcessedForRubric($slug);
            if ($cap > 0 && $processed >= $cap) {
                ++$skippedDone;
                continue;
            }
            $total = (int) $this->conn->executeQuery(
                "SELECT COALESCE(value,'0') FROM setting WHERE name = :n",
                ['n' => 'openalex.total.'.$slug],
            )->fetchOne();
            if ($total > 0 && $processed >= $total) {
                ++$skippedDone;
                continue;
            }

            // Déjà en cours (récent, non orphelin) ou déjà en file ?
            if ($this->isBusy($id, $slug)) {
                ++$skippedBusy;
                continue;
            }

            $this->bus->dispatch(new HarvestRubric($id));
            ++$enqueued;
        }

        $io->success(\sprintf(
            '%d rubrique(s) enfilée(s) · %d déjà terminée(s) · %d déjà en cours/en file.',
            $enqueued, $skippedDone, $skippedBusy,
        ));

        return Command::SUCCESS;
    }

    private function isBusy(int $id, string $slug): bool
    {
        $running = (int) $this->conn->executeQuery(
            "SELECT count(*) FROM ingestion_job
              WHERE query->>'rubric' = :slug AND status = 'running' AND started_at > now() - interval '15 minutes'",
            ['slug' => $slug],
        )->fetchOne();
        if ($running > 0) {
            return true;
        }
        try {
            $pending = (int) $this->conn->executeQuery(
                "SELECT count(*) FROM messenger_messages WHERE delivered_at IS NULL AND body LIKE '%HarvestRubric%' AND body LIKE :p",
                ['p' => '%i:'.$id.';%'],
            )->fetchOne();

            return $pending > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
