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
 * Reprise du texte intégral : ré-arme (fulltext_fetched_at = NULL) les articles en
 * accès libre déjà TENTÉS mais SANS fragments (échecs antérieurs — souvent dus à
 * l'ancien pipeline : pas de User-Agent, redirections cookies non suivies, pdftotext).
 * Ils repassent alors dans la file de `app:fulltext:fetch`, désormais via GROBID +
 * téléchargement corrigé. Priorisé par citations ; borné par passage.
 *
 *   bin/console app:fulltext:retry --limit=500
 */
#[AsCommand(name: 'app:fulltext:retry', description: 'Ré-arme les échecs de texte intégral (OA, top-cités) pour une nouvelle tentative GROBID.')]
final class RetryFulltextCommand extends Command
{
    public function __construct(private readonly Connection $conn)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre d\'articles à ré-armer', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        $count = (int) $this->conn->executeStatement(
            "UPDATE publication SET fulltext_fetched_at = NULL
              WHERE id IN (
                SELECT id FROM publication
                 WHERE fulltext_fetched_at IS NOT NULL AND oa_url IS NOT NULL AND oa_url <> ''
                   AND id NOT IN (SELECT publication_id FROM publication_chunk)
                 ORDER BY cited_by_count DESC, id DESC
                 LIMIT :n
              )",
            ['n' => $limit],
        );

        $io->success(\sprintf('%d article(s) ré-armé(s) — seront retraités par app:fulltext:fetch (GROBID).', $count));

        return Command::SUCCESS;
    }
}
