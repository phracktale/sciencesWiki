<?php

declare(strict_types=1);

namespace App\Analysis\Command;

use App\Analysis\Claim\ClaimExtractor;
use App\Repository\TreeNodeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Extrait les assertions structurées (claims) des publications d'un nœud via le
 * LLM (cf. docs/spec-controverses-lacunes.md §5 / §7). Gros consommateur LLM :
 * traité par lots avec flush périodique dans {@see ClaimExtractor}.
 *
 * Outil de debug/backfill : en exploitation, c'est l'orchestrateur
 * (`analysis:run`) qui enchaîne cette étape.
 *
 *   bin/console analysis:extract-claims --node=biochimie --reextract
 */
#[AsCommand(name: 'analysis:extract-claims', description: 'Extrait les assertions (claims) des publications d\'un nœud (LLM).')]
final class ExtractClaimsCommand extends Command
{
    public function __construct(
        private readonly ClaimExtractor $extractor,
        private readonly TreeNodeRepository $nodes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('node', null, InputOption::VALUE_REQUIRED, 'Slug du nœud à traiter')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de publications', '1000')
            ->addOption('reextract', null, InputOption::VALUE_NONE, 'Ré-extrait même les publications déjà traitées');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug = (string) $input->getOption('node');
        if ('' === $slug) {
            $io->error('L\'option --node=<slug> est requise.');

            return Command::FAILURE;
        }
        $node = $this->nodes->findOneBy(['slug' => $slug]);
        if (null === $node) {
            $io->error(\sprintf('Nœud « %s » introuvable.', $slug));

            return Command::FAILURE;
        }

        $result = $this->extractor->extractForNode(
            $node,
            max(1, (int) $input->getOption('limit')),
            (bool) $input->getOption('reextract'),
        );

        $io->success(\sprintf('%d publication(s) traitée(s), %d claim(s) extrait(s).', $result['publications'], $result['claims']));

        return Command::SUCCESS;
    }
}
