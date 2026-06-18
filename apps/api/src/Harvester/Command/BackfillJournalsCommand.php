<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Connector\OpenAlex\OpenAlexConnector;
use App\Harvester\Dto\RawRef;
use App\Harvester\Pipeline\PublicationImporter;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rattrapage du référentiel éditeurs/revues sur le stock déjà moissonné : pour
 * les publications sans revue rattachée, re-interroge OpenAlex (par identifiant)
 * afin de renseigner la revue, son éditeur et le lien canonique (landing page).
 *
 * Idempotent et borné ; le reliquat est repris au lancement suivant. À planifier
 * (cron) ou à lancer manuellement par lots.
 *
 *   bin/console app:journals:backfill --limit=200
 */
#[AsCommand(name: 'app:journals:backfill', description: 'Rattrape la revue/éditeur + lien canonique des publications déjà moissonnées.')]
final class BackfillJournalsCommand extends Command
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly OpenAlexConnector $connector,
        private readonly PublicationImporter $importer,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de publications à rattraper', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        $pubs = $this->publications->findNeedingJournal($limit);
        if ([] === $pubs) {
            $io->success('Aucune publication à rattraper (revue déjà renseignée).');

            return Command::SUCCESS;
        }

        $this->importer->reset();
        $done = 0;
        $withJournal = 0;
        foreach ($pubs as $publication) {
            $openAlexId = $publication->getExternalIds()['openalex'] ?? null;
            if (null === $openAlexId || '' === $openAlexId) {
                continue;
            }
            try {
                $raw = $this->connector->fetchMetadata(new RawRef('openalex', (string) $openAlexId));
                if ($this->importer->applySourceAndLanding($publication, $raw)) {
                    ++$done;
                    if (null !== $publication->getJournal()) {
                        ++$withJournal;
                    }
                }
            } catch (\Throwable $e) {
                $io->warning(\sprintf('#%d (%s) : %s', $publication->getId(), $openAlexId, $e->getMessage()));
            }
            // Pool « poli » d'OpenAlex : on reste largement sous la limite.
            usleep(120_000);
        }
        $this->em->flush();

        $io->success(\sprintf('%d publication(s) traitée(s), %d enrichie(s), %d avec revue.', \count($pubs), $done, $withJournal));

        return Command::SUCCESS;
    }
}
