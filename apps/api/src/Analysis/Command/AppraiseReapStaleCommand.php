<?php

declare(strict_types=1);

namespace App\Analysis\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Filet de sécurité (watchdog) des évaluations à la demande. Chaque marqueur
 * `*_appraising_at` est posé au dispatch et TOUJOURS levé par le handler (bloc
 * finally). Il ne peut donc rester « collé » que si le worker meurt EN COURS de
 * traitement (recyclage --time-limit, redéploiement, OOM) : le finally saute, le
 * marqueur fuit, et le loader de l'outil tourne indéfiniment côté UI.
 *
 * Cette commande lève les marqueurs plus vieux que le seuil, débloquant l'UI
 * (l'utilisateur peut relancer). Aucune perte : le message reste en file et sera
 * auto-redélivré (redeliver_timeout de la file « appraisal »).
 *
 * À planifier (cron, ex. toutes les 10 min). Un appraisal dure au pire 2 ×
 * LLM_TIMEOUT (900 s) = 30 min : le seuil par défaut (40 min) ne touche jamais
 * un job réellement en cours.
 *
 *   bin/console app:appraisal:reap-stale --older-than=40
 */
#[AsCommand(name: 'app:appraisal:reap-stale', description: 'Lève les marqueurs d\'évaluation (AXIS/RoB2/AMSTAR-2/MMAT) orphelins (worker mort en cours).')]
final class AppraiseReapStaleCommand extends Command
{
    /** Colonnes « en cours d'évaluation » à surveiller (une par outil). */
    private const MARKERS = [
        'axis_appraising_at',
        'rob2_appraising_at',
        'amstar2_appraising_at',
        'mmat_appraising_at',
    ];

    public function __construct(
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Âge minimal en minutes pour considérer un marqueur d\'évaluation comme orphelin', '40');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $minutes = max(1, (int) $input->getOption('older-than'));

        // $minutes est un entier strict (max(1, (int) …)) : inliné sans risque d'injection.
        // Un placeholder nommé réutilisé 8 fois n'est pas fiable avec le driver pgsql.
        $threshold = \sprintf('now() - make_interval(mins => %d)', $minutes);
        // Ne remet à NULL QUE les marqueurs réellement périmés (CASE par colonne) ;
        // ne touche que les lignes ayant au moins un marqueur périmé (WHERE).
        $sets = array_map(
            static fn (string $c): string => \sprintf('%1$s = CASE WHEN %1$s < %2$s THEN NULL ELSE %1$s END', $c, $threshold),
            self::MARKERS,
        );
        $where = implode(' OR ', array_map(static fn (string $c): string => \sprintf('%s < %s', $c, $threshold), self::MARKERS));

        $count = (int) $this->conn->executeStatement(
            \sprintf('UPDATE publication SET %s WHERE %s', implode(', ', $sets), $where),
        );

        $io->success(\sprintf('%d évaluation(s) orpheline(s) débloquée(s) (seuil %d min).', $count, $minutes));

        return Command::SUCCESS;
    }
}
