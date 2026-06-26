<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism\Command;

use App\Analysis\Plagiarism\PublicationFingerprinter;
use App\Repository\ChunkFingerprintRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Calcule les empreintes MinHash/LSH manquantes (drain) — cf. docs/spec-plagiat.md §7.
 *
 *   bin/console app:plagiarism:fingerprint --limit=200
 *   bin/console app:plagiarism:fingerprint --publication=123
 */
#[AsCommand(name: 'app:plagiarism:fingerprint', description: 'Empreintes MinHash/LSH des fragments plein texte (antiplagiat).')]
final class FingerprintCommand extends Command
{
    public function __construct(
        private readonly ChunkFingerprintRepository $repository,
        private readonly PublicationFingerprinter $fingerprinter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de publications à empreinter', '200');
        $this->addOption('publication', null, InputOption::VALUE_REQUIRED, 'Empreinte une seule publication (id)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        $io = new SymfonyStyle($input, $output);

        $one = $input->getOption('publication');
        $ids = null !== $one
            ? [max(1, (int) $one)]
            : $this->repository->publicationsNeedingFingerprint(max(1, (int) $input->getOption('limit')));

        if ([] === $ids) {
            $io->success('Aucune publication à empreinter.');

            return Command::SUCCESS;
        }

        $pubs = 0;
        $chunks = 0;
        foreach ($ids as $id) {
            $chunks += $this->fingerprinter->fingerprint($id);
            ++$pubs;
        }

        $io->success(\sprintf('Empreintes calculées : %d publication(s), %d fragment(s).', $pubs, $chunks));

        return Command::SUCCESS;
    }
}
