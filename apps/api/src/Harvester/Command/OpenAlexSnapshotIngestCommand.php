<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Harvester\Connector\OpenAlex\OpenAlexMapper;
use App\Harvester\Pipeline\PublicationImporter;
use App\Repository\SourceRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ingestion à partir du SNAPSHOT OpenAlex local (téléchargé via `aws s3 sync
 * s3://openalex …`), sans passer par l'API (ni limite de débit ni paywall
 * `from_updated_date`).
 *
 * On NE charge PAS tout OpenAlex (250 M+ œuvres : embeddings/index ingérables) :
 * on SÉLECTIONNE le sous-ensemble pertinent — œuvres dont le `primary_topic`
 * appartient à la taxonomie de l'arbre SciencesWiki — filtré sur la qualité
 * (année, citations, langue, type, résumé présent, non rétracté). On réutilise
 * le mapping (OpenAlexMapper) et l'import (PublicationImporter, dédup DOI). Le
 * reste est DÉCOUPLÉ comme la moisson API : l'embedding et le placement sont
 * assurés par les crons habituels (embed-drain, placement). Idempotent (dédup) et
 * reprenable (--skip-files).
 *
 *   bin/console app:openalex:ingest-snapshot --dir=/data2/openalex-snapshot \
 *       --since=2015 --min-citations=5 --langs=en,fr --max=200000
 */
#[AsCommand(name: 'app:openalex:ingest-snapshot', description: 'Ingère le sous-ensemble pertinent du snapshot OpenAlex local (rapide, hors API).')]
final class OpenAlexSnapshotIngestCommand extends Command
{
    /** Clé setting (JSON) où la progression est publiée pour le back-office. */
    public const PROGRESS_KEY = 'openalex.snapshot_progress';

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly PublicationImporter $importer,
        private readonly EntityManagerInterface $em,
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Racine du snapshot (contient data/works/…)', '/data2/openalex-snapshot')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Année de publication minimale (0 = pas de filtre)', '2015')
            ->addOption('min-citations', null, InputOption::VALUE_REQUIRED, 'Citations minimales', '5')
            ->addOption('langs', null, InputOption::VALUE_REQUIRED, 'Langues acceptées (csv ; vide = toutes)', 'en,fr')
            ->addOption('types', null, InputOption::VALUE_REQUIRED, 'Types acceptés (csv ; vide = tous)', 'article')
            ->addOption('allow-no-abstract', null, InputOption::VALUE_NONE, 'Accepter les œuvres SANS résumé (déconseillé : inutiles au RAG)')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Plafond total d’œuvres retenues (0 = illimité)', '0')
            ->addOption('files', null, InputOption::VALUE_REQUIRED, 'Limiter aux N premiers fichiers (0 = tous ; utile pour tester)', '0')
            ->addOption('skip-files', null, InputOption::VALUE_REQUIRED, 'Sauter les N premiers fichiers (reprise)', '0')
            ->addOption('flush', null, InputOption::VALUE_REQUIRED, 'Taille de lot (flush + clear mémoire)', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        $io = new SymfonyStyle($input, $output);

        $dir = rtrim((string) $input->getOption('dir'), '/');
        $since = (int) $input->getOption('since');
        $minCit = (int) $input->getOption('min-citations');
        $langSet = $this->csvSet((string) $input->getOption('langs'));
        $typeSet = $this->csvSet((string) $input->getOption('types'));
        $requireAbstract = !$input->getOption('allow-no-abstract');
        $max = max(0, (int) $input->getOption('max'));
        $fileLimit = max(0, (int) $input->getOption('files'));
        $skipFiles = max(0, (int) $input->getOption('skip-files'));
        $flush = max(50, (int) $input->getOption('flush'));

        $source = $this->sources->findOneByCode(OpenAlexMapper::SOURCE_CODE);
        if (null === $source) {
            $io->error('Source « openalex » introuvable (lancer la seed des sources).');

            return Command::FAILURE;
        }

        // Concepts de l'arbre (forme courte « domains/3 », « fields/22 », « subfields/2746 »,
        // ou topic « T123 ») → sert à SÉLECTIONNER les œuvres de notre taxonomie.
        $concepts = [];
        foreach ($this->conn->executeQuery('SELECT DISTINCT openalex_concept_id FROM tree_node WHERE openalex_concept_id IS NOT NULL')->fetchFirstColumn() as $c) {
            $concepts[$this->shortId((string) $c)] = true;
        }
        if ([] === $concepts) {
            $io->error('Aucun concept OpenAlex sur l’arbre (taxonomie non seedée ?).');

            return Command::FAILURE;
        }

        $files = glob($dir.'/data/works/*/*.gz') ?: [];
        sort($files);
        $totalFiles = \count($files);
        if (0 === $totalFiles) {
            $io->error(\sprintf('Aucun fichier trouvé dans %s/data/works/*/*.gz', $dir));

            return Command::FAILURE;
        }
        if ($skipFiles > 0) {
            $files = \array_slice($files, $skipFiles);
        }
        if ($fileLimit > 0) {
            $files = \array_slice($files, 0, $fileLimit);
        }

        $io->writeln(\sprintf('%d concept(s) d’arbre · %d/%d fichier(s) à traiter · filtres : depuis %d, ≥%d cit., langues=%s, types=%s%s.',
            \count($concepts), \count($files), $totalFiles, $since, $minCit,
            [] === $langSet ? 'toutes' : implode('|', array_keys($langSet)),
            [] === $typeSet ? 'tous' : implode('|', array_keys($typeSet)),
            $requireAbstract ? ', résumé requis' : ''));

        $mapper = new OpenAlexMapper();
        $this->importer->reset();
        $scanned = 0;
        $selected = 0;
        $created = 0;
        $pending = 0;
        $stop = false;

        // Suivi temps réel pour le back-office (clé setting JSON, survit aux redémarrages).
        $startedAt = new \DateTimeImmutable();
        $totalToDo = $skipFiles + \count($files);
        $writeProgress = function (int $doneFiles, string $partition, bool $finished) use ($startedAt, $totalFiles, $totalToDo, $skipFiles, &$scanned, &$selected, &$created): void {
            $this->writeProgress([
                'started_at' => $startedAt->format(\DateTimeInterface::ATOM),
                'updated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'total_files' => $totalFiles,
                'skip_files' => $skipFiles,
                'target_last_file' => $totalToDo,
                'done_files' => $doneFiles,
                'scanned' => $scanned,
                'selected' => $selected,
                'created' => $created,
                'partition' => $partition,
                'finished' => $finished,
            ]);
        };
        $writeProgress($skipFiles, '', false);

        foreach ($files as $i => $file) {
            $fp = gzopen($file, 'rb');
            if (false === $fp) {
                $io->warning('Fichier illisible : '.$file);
                continue;
            }
            while (false !== ($line = gzgets($fp))) {
                ++$scanned;
                $work = json_decode($line, true);
                if (!\is_array($work) || !$this->keep($work, $since, $minCit, $typeSet, $langSet, $requireAbstract, $concepts)) {
                    continue;
                }
                try {
                    $res = $this->importer->import($mapper->map($work), $source);
                    ++$selected;
                    if ($res->created) {
                        ++$created;
                    }
                    ++$pending;
                } catch (\Throwable) {
                    continue; // une œuvre malformée ne stoppe pas la passe
                }
                if ($pending >= $flush) {
                    $this->em->flush();
                    $this->em->clear();
                    $this->importer->reset();
                    // L'EntityManager vidé détache la source : on la re-récupère managée.
                    $source = $this->sources->findOneByCode(OpenAlexMapper::SOURCE_CODE);
                    $pending = 0;
                }
                if ($max > 0 && $selected >= $max) {
                    $stop = true;
                    break;
                }
            }
            gzclose($fp);
            $partition = basename(\dirname($file));
            $io->writeln(\sprintf('  [%d/%d] %s — scannées=%d · retenues=%d (dont %d nouvelles)',
                $skipFiles + $i + 1, $totalFiles, $partition.'/'.basename($file), $scanned, $selected, $created));
            $writeProgress($skipFiles + $i + 1, $partition, false);
            if ($stop) {
                break;
            }
        }
        if ($pending > 0) {
            $this->em->flush();
        }

        $writeProgress($totalToDo, 'terminé', true);
        $io->success(\sprintf('Terminé : %d œuvres scannées, %d retenues, %d nouvelles publications. Embedding + placement assurés par les crons habituels.', $scanned, $selected, $created));

        return Command::SUCCESS;
    }

    /**
     * Persiste la progression dans la clé setting « openalex.snapshot_progress »
     * (JSON). Best-effort : ne doit jamais interrompre l'ingestion.
     *
     * @param array<string,mixed> $data
     */
    private function writeProgress(array $data): void
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO setting (name, value) VALUES (:n, :v)
                 ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value',
                ['n' => self::PROGRESS_KEY, 'v' => json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)],
            );
        } catch (\Throwable) {
            // Pas de suivi ≠ échec d'ingestion.
        }
    }

    /**
     * @param array<string,mixed> $work
     * @param array<string,bool>  $typeSet
     * @param array<string,bool>  $langSet
     * @param array<string,bool>  $concepts
     */
    private function keep(array $work, int $since, int $minCit, array $typeSet, array $langSet, bool $requireAbstract, array $concepts): bool
    {
        if (true === ($work['is_retracted'] ?? false)) {
            return false;
        }
        $type = (string) ($work['type'] ?? '');
        if ('paratext' === $type) {
            return false;
        }
        if ([] !== $typeSet && !isset($typeSet[$type])) {
            return false;
        }
        if ($since > 0 && (int) ($work['publication_year'] ?? 0) < $since) {
            return false;
        }
        if ($minCit > 0 && (int) ($work['cited_by_count'] ?? 0) < $minCit) {
            return false;
        }
        if ([] !== $langSet && !isset($langSet[(string) ($work['language'] ?? '')])) {
            return false;
        }
        if ($requireAbstract && empty($work['abstract_inverted_index'])) {
            return false;
        }

        return $this->inTaxonomy($work, $concepts);
    }

    /**
     * L'œuvre relève-t-elle de notre taxonomie ? (son domaine/champ/sous-champ/topic
     * primaire correspond à un concept de l'arbre).
     *
     * @param array<string,mixed> $work
     * @param array<string,bool>  $concepts
     */
    private function inTaxonomy(array $work, array $concepts): bool
    {
        $pt = $work['primary_topic'] ?? null;
        if (!\is_array($pt)) {
            return false;
        }
        $ids = [
            $pt['subfield']['id'] ?? null,
            $pt['field']['id'] ?? null,
            $pt['domain']['id'] ?? null,
            $pt['id'] ?? null,
        ];
        foreach ($ids as $id) {
            if (null !== $id && isset($concepts[$this->shortId((string) $id)])) {
                return true;
            }
        }

        return false;
    }

    /** « https://openalex.org/fields/22 » → « fields/22 » (forme stockée sur l'arbre). */
    private function shortId(string $id): string
    {
        return str_replace('https://openalex.org/', '', $id);
    }

    /** @return array<string,bool> ensemble (set) des valeurs csv non vides */
    private function csvSet(string $csv): array
    {
        $set = [];
        foreach (explode(',', $csv) as $v) {
            $v = trim($v);
            if ('' !== $v) {
                $set[$v] = true;
            }
        }

        return $set;
    }
}
