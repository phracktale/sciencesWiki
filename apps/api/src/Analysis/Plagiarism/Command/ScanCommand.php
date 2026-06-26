<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism\Command;

use App\Analysis\Plagiarism\PlagiarismScanner;
use App\Repository\ChunkFingerprintRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Détection de doublons/plagiat intra-corpus (Lot 1, verbatim) — cf. docs/spec-plagiat.md §7.
 * Idempotent (un finding par paire, mis à jour).
 *
 *   bin/console app:plagiarism:scan --publication=123
 *   bin/console app:plagiarism:scan --limit=100
 */
#[AsCommand(name: 'app:plagiarism:scan', description: 'Détecte les rapprochements de contenu (doublons/plagiat) dans le corpus.')]
final class ScanCommand extends Command
{
    public function __construct(
        private readonly ChunkFingerprintRepository $repository,
        private readonly PlagiarismScanner $scanner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de publications à scanner', '100');
        $this->addOption('publication', null, InputOption::VALUE_REQUIRED, 'Scanne une seule publication (id)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        $io = new SymfonyStyle($input, $output);

        $one = $input->getOption('publication');
        $ids = null !== $one
            ? [max(1, (int) $one)]
            : $this->repository->publicationsWithFingerprints(max(1, (int) $input->getOption('limit')));

        if ([] === $ids) {
            $io->warning('Aucune publication empreintée à scanner (lancer d\'abord app:plagiarism:fingerprint).');

            return Command::SUCCESS;
        }

        $scanned = 0;
        $findings = 0;
        foreach ($ids as $id) {
            $findings += $this->scanner->scan($id);
            ++$scanned;
        }

        $io->success(\sprintf('%d publication(s) scannée(s), %d rapprochement(s) créés/mis à jour.', $scanned, $findings));

        return Command::SUCCESS;
    }
}
