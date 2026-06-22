<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Message\IngestFulltext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Enfile l'ingestion du texte intégral d'un lot de publications en accès libre
 * (PDF éditeur → GROBID), drainé EN PARALLÈLE par le pool de workers « fulltext ».
 * Priorise les articles avec TEI GROBID disponible et les plus cités. « Claim »
 * (fulltext_fetched_at = now()) pour ne pas ré-enfiler les mêmes au passage suivant.
 *
 *   bin/console app:fulltext:enqueue --limit=2000
 */
#[AsCommand(name: 'app:fulltext:enqueue', description: 'Enfile l\'ingestion parallèle du texte intégral (PDF→GROBID) des articles OA.')]
final class EnqueueFulltextCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de publications à enfiler', '2000');
        $this->addOption('max-queue', null, InputOption::VALUE_REQUIRED, 'Ne pas enfiler si la file dépasse ce seuil (anti-engorgement)', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $maxQueue = max(0, (int) $input->getOption('max-queue'));

        // Anti-engorgement : si la file fulltext est déjà bien remplie, on n'ajoute rien.
        $pending = (int) $this->conn->executeQuery("SELECT count(*) FROM messenger_messages WHERE queue_name = 'fulltext' AND delivered_at IS NULL")->fetchOne();
        if ($maxQueue > 0 && $pending >= $maxQueue) {
            $io->success(\sprintf('File fulltext déjà à %d (≥ %d) — rien enfilé.', $pending, $maxQueue));

            return Command::SUCCESS;
        }

        $ids = $this->conn->executeQuery(
            "SELECT id FROM publication
              WHERE fulltext_fetched_at IS NULL AND oa_url IS NOT NULL AND oa_url <> ''
              ORDER BY has_grobid_xml DESC, cited_by_count DESC, id DESC
              LIMIT :n",
            ['n' => $limit],
        )->fetchFirstColumn();

        if ([] === $ids) {
            $io->success('Aucune publication à enfiler (toutes tentées).');

            return Command::SUCCESS;
        }

        // « Claim » du lot pour éviter les doublons d'enfilement.
        $this->conn->executeStatement(
            'UPDATE publication SET fulltext_fetched_at = now() WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        foreach ($ids as $id) {
            $this->bus->dispatch(new IngestFulltext((int) $id));
        }

        $io->success(\sprintf('%d publication(s) enfilée(s) pour ingestion parallèle (file fulltext : %d).', \count($ids), $pending + \count($ids)));

        return Command::SUCCESS;
    }
}
