<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * « Récolte » les jobs de moisson restés en « running » alors qu'aucun worker ne
 * les traite plus : cela arrive quand un worker est recyclé (--time-limit horaire)
 * ou redéployé pendant un job, qui reste alors bloqué en cours (ligne fantôme).
 *
 * Ces jobs ont importé une partie de leurs publications (commit par page) ; la
 * moisson REPRENDRA au curseur suivant. On les marque donc « done » (et non
 * « failed » : ce n'est pas une erreur), avec finished_at, pour libérer le tableau.
 *
 * À planifier (cron, ex. toutes les 10 min). Un job sain dure ~2 min : le seuil
 * par défaut (15 min) ne touche jamais un job réellement actif.
 *
 *   bin/console app:harvest:reap-stale --older-than=15
 */
#[AsCommand(name: 'app:harvest:reap-stale', description: 'Clôt les jobs de moisson « running » orphelins (worker recyclé/redéployé).')]
final class ReapStaleJobsCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Âge minimal en minutes pour considérer un job « running » comme orphelin', '15');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $minutes = max(1, (int) $input->getOption('older-than'));

        // IngestionStatus::Partial = 'partial' : interrompu après progrès partiel
        // (et non 'failed' — pas une erreur ; le curseur reprendra).
        $count = (int) $this->conn->executeStatement(
            "UPDATE ingestion_job
                SET status = 'partial', finished_at = now()
              WHERE status = 'running'
                AND started_at < now() - make_interval(mins => :m)",
            ['m' => $minutes],
        );

        $io->success(\sprintf('%d job(s) de moisson orphelin(s) clôturé(s) (seuil %d min).', $count, $minutes));

        return Command::SUCCESS;
    }
}
