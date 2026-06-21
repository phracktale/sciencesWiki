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
 * Recompte author.publication_count (dénormalisé) en une passe : la moisson
 * ajoute des authorships en continu, on resynchronise périodiquement (cron).
 *
 *   bin/console app:authors:recount
 */
#[AsCommand(name: 'app:authors:recount', description: 'Resynchronise author.publication_count (nb de publications par auteur).')]
final class RecountAuthorsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        $io = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();

        $io->writeln('Recomptage des publications par auteur…');
        $updated = $conn->executeStatement(
            'WITH counts AS (SELECT author_id, count(*) AS c FROM authorship GROUP BY author_id)
             UPDATE author a SET publication_count = c.c
             FROM counts c
             WHERE a.id = c.author_id AND a.publication_count IS DISTINCT FROM c.c'
        );
        // Auteurs ayant perdu toutes leurs authorships (rare).
        $zeroed = $conn->executeStatement(
            'UPDATE author SET publication_count = 0
             WHERE publication_count <> 0
               AND NOT EXISTS (SELECT 1 FROM authorship au WHERE au.author_id = author.id)'
        );

        $io->success(\sprintf('Recompté : %d auteur(s) mis à jour, %d remis à zéro.', $updated, $zeroed));

        return Command::SUCCESS;
    }
}
