<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Ai\FulltextIngester;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Récupère le texte intégral des publications en accès libre (PDF chez l'éditeur
 * ou le dépôt) puis le vectorise — découplé de la moisson, pour rattraper le
 * stock de liens OA. À planifier (cron). Idempotent : chaque publication n'est
 * tentée qu'une fois (fulltext_fetched_at), le reliquat est repris au lancement suivant.
 *
 *   bin/console app:fulltext:fetch --limit=50
 */
#[AsCommand(name: 'app:fulltext:fetch', description: 'Télécharge et vectorise le texte intégral des publications en accès libre.')]
final class FetchFulltextCommand extends Command
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly FulltextIngester $fulltext,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de publications à traiter', '50');
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Traiter UNE publication précise (par id) — test/ops', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        // --id : ré-ingère une publication précise (bypass la sélection ; utile pour
        // tester le repli résolveur ou re-traiter une étude à la demande).
        if (null !== ($one = $input->getOption('id'))) {
            $pub = $this->publications->find((int) $one);
            $pubs = null !== $pub ? [$pub] : [];
        } else {
            $pubs = $this->publications->findNeedingFulltext($limit);
        }
        if ([] === $pubs) {
            $io->success('Aucune publication en attente de texte intégral.');

            return Command::SUCCESS;
        }

        $chunks = 0;
        $ok = 0;
        foreach ($pubs as $publication) {
            $n = $this->fulltext->ingest($publication);
            $chunks += $n;
            if ($n > 0) {
                ++$ok;
            }
        }
        $this->em->flush();

        $io->success(\sprintf('%d publication(s) traitée(s), %d avec texte intégral (%d fragments).', \count($pubs), $ok, $chunks));

        return Command::SUCCESS;
    }
}
