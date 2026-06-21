<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rafraîchit les vues matérialisées des statistiques du tableau de bord. À
 * planifier (cron). CONCURRENTLY (ne bloque pas la lecture) ; repli sur un
 * REFRESH simple au tout premier passage (vue créée WITH NO DATA).
 *
 *   bin/console app:stats:refresh
 */
#[AsCommand(name: 'app:stats:refresh', description: 'Rafraîchit les vues matérialisées des stats (dashboard).')]
final class RefreshStatsCommand extends Command
{
    private const VIEWS = ['dashboard_stats', 'dashboard_type_breakdown', 'dashboard_domain_stats'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        $io = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();

        foreach (self::VIEWS as $view) {
            try {
                $conn->executeStatement('REFRESH MATERIALIZED VIEW CONCURRENTLY '.$view);
            } catch (\Throwable) {
                // 1er passage : vue jamais peuplée → REFRESH simple (bloquant mais bref).
                $conn->executeStatement('REFRESH MATERIALIZED VIEW '.$view);
            }
            $io->writeln('✓ '.$view);
        }

        $io->success('Vues de statistiques rafraîchies.');

        return Command::SUCCESS;
    }
}
