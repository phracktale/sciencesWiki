<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Catalog\PublicationType;
use App\Search\SearchEngine;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Indexe le corpus (papiers PRIMAIRES de recherche) dans Meilisearch pour la
 * recherche plein-texte tolérante aux fautes. Par lots, repris au curseur (id).
 *
 *   bin/console app:search:index               # tout (papiers primaires)
 *   bin/console app:search:index --from=0 --batch=2000 --max=0
 */
#[AsCommand(name: 'app:search:index', description: 'Indexe les papiers primaires dans Meilisearch (recherche tolérante aux fautes).')]
final class SearchIndexCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchEngine $engine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Taille de lot', '2000');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Reprendre après cet id', '0');
        $this->addOption('max', null, InputOption::VALUE_REQUIRED, 'Plafond de documents (0 = tout)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        $io = new SymfonyStyle($input, $output);
        if (!$this->engine->enabled()) {
            $io->error('Meilisearch non configuré (MEILI_URL).');

            return Command::FAILURE;
        }

        $batch = max(100, (int) $input->getOption('batch'));
        $fromId = max(0, (int) $input->getOption('from'));
        $max = max(0, (int) $input->getOption('max'));
        $types = PublicationType::PRIMARY;

        $io->writeln('Configuration de l\'index…');
        $this->engine->configure();

        $conn = $this->em->getConnection();
        $total = 0;
        while (true) {
            $rows = $conn->executeQuery(
                "SELECT p.id, p.doi, p.title, left(coalesce(p.abstract,''), 1500) AS abstract,
                        COALESCE(j.name, p.venue) AS journal,
                        EXTRACT(YEAR FROM p.publication_date)::int AS year,
                        p.type, p.oa_status, p.cited_by_count, p.fwci, p.oa_url, p.retraction_status
                   FROM publication p
                   LEFT JOIN journal j ON j.id = p.journal_id
                  WHERE p.id > :from AND p.type IN (:types) AND p.title IS NOT NULL
                  ORDER BY p.id ASC
                  LIMIT :batch",
                ['from' => $fromId, 'types' => $types, 'batch' => $batch],
                ['types' => ArrayParameterType::STRING, 'batch' => \PDO::PARAM_INT, 'from' => \PDO::PARAM_INT],
            )->fetchAllAssociative();

            if ([] === $rows) {
                break;
            }

            $docs = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'doi' => $r['doi'],
                'title' => $r['title'],
                'abstract' => $r['abstract'],
                'journal' => $r['journal'],
                'year' => null !== $r['year'] ? (int) $r['year'] : null,
                'type' => $r['type'],
                'oa_status' => $r['oa_status'],
                'cited_by_count' => (int) ($r['cited_by_count'] ?? 0),
                'fwci' => null !== $r['fwci'] ? (float) $r['fwci'] : null,
                'oa_url' => $r['oa_url'],
                'retraction_status' => $r['retraction_status'],
            ], $rows);

            $this->engine->indexBatch($docs);
            $total += \count($docs);
            $fromId = (int) end($rows)['id'];
            $io->writeln(\sprintf('  %d indexés (dernier id %d)', $total, $fromId));

            if ($max > 0 && $total >= $max) {
                break;
            }
        }

        $io->success(\sprintf('%d documents envoyés à Meilisearch (reprise possible avec --from=%d).', $total, $fromId));

        return Command::SUCCESS;
    }
}
