<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Entity\Source;
use App\Enum\ApiType;
use App\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enregistre le registre des sources (cf. spec §3.1) : toutes référencées, mais
 * seules les 3 premières (OpenAlex, Unpaywall, arXiv) actives en Phase 1.
 *
 * Idempotent : relancer la commande met à jour sans dupliquer.
 */
#[AsCommand(name: 'harvester:seed-sources', description: 'Enregistre/maj le registre des sources de moisson.')]
final class SeedSourcesCommand extends Command
{
    /**
     * @var list<array{code:string,name:string,api:ApiType,active:bool,phase:int}>
     */
    private const REGISTRY = [
        ['code' => 'openalex', 'name' => 'OpenAlex', 'api' => ApiType::Rest, 'active' => true, 'phase' => 1],
        ['code' => 'unpaywall', 'name' => 'Unpaywall', 'api' => ApiType::Rest, 'active' => true, 'phase' => 1],
        ['code' => 'arxiv', 'name' => 'arXiv', 'api' => ApiType::OaiPmh, 'active' => true, 'phase' => 1],
        ['code' => 'europepmc', 'name' => 'Europe PMC', 'api' => ApiType::Rest, 'active' => false, 'phase' => 2],
        ['code' => 'hal', 'name' => 'HAL', 'api' => ApiType::Rest, 'active' => false, 'phase' => 2],
        ['code' => 'doaj', 'name' => 'DOAJ', 'api' => ApiType::Rest, 'active' => false, 'phase' => 2],
        ['code' => 'core', 'name' => 'CORE', 'api' => ApiType::Rest, 'active' => false, 'phase' => 2],
        ['code' => 'openaire', 'name' => 'OpenAIRE', 'api' => ApiType::Rest, 'active' => false, 'phase' => 3],
        ['code' => 'persee', 'name' => 'Persée', 'api' => ApiType::Rest, 'active' => false, 'phase' => 3],
    ];

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;
        $updated = 0;

        foreach (self::REGISTRY as $entry) {
            $source = $this->sources->findOneByCode($entry['code']);
            if (null === $source) {
                $source = new Source($entry['code'], $entry['name'], $entry['api']);
                $this->em->persist($source);
                ++$created;
            } else {
                $source->setName($entry['name'])->setApiType($entry['api']);
                ++$updated;
            }

            $source->setActive($entry['active'])->setPhase($entry['phase']);
        }

        $this->em->flush();
        $io->success(\sprintf('Sources enregistrées : %d créée(s), %d mise(s) à jour.', $created, $updated));

        return Command::SUCCESS;
    }
}
