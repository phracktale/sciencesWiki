<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Entity\Source;
use App\Enum\ApiType;
use App\Harvester\Dto\RawAuthor;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Pipeline\PublicationImporter;
use App\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe une bibliothèque Zotero (export RDF) dans le corpus. Réutilise le
 * pipeline de moisson (dédoublonnage par DOI, auteurs, provenance). Les
 * publications importées sont ensuite enrichies par `harvester:embed` puis
 * placées par `harvester:suggest-placement`.
 *
 *   bin/console wiki:import-zotero /tmp/covid.rdf
 */
#[AsCommand(name: 'wiki:import-zotero', description: 'Importe une bibliothèque Zotero (RDF) dans le corpus.')]
final class ImportZoteroCommand extends Command
{
    private const NS = [
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'z' => 'http://www.zotero.org/namespaces/export#',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'bib' => 'http://purl.org/net/biblio#',
        'foaf' => 'http://xmlns.com/foaf/0.1/',
        'prism' => 'http://prismstandard.org/namespaces/1.2/basic/',
    ];

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly PublicationImporter $importer,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier Zotero RDF')
            ->addOption('source-code', null, InputOption::VALUE_REQUIRED, 'Code de la source', 'zotero')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nb max d\'items (0 = tous)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $limit = max(0, (int) $input->getOption('limit'));

        if (!is_file($file)) {
            $io->error(\sprintf('Fichier introuvable : %s', $file));

            return Command::FAILURE;
        }

        $source = $this->resolveSource((string) $input->getOption('source-code'));
        $io->title(\sprintf('Import Zotero RDF — %s', basename($file)));

        $reader = new \XMLReader();
        if (false === $reader->open($file)) {
            $io->error('Impossible d\'ouvrir le fichier RDF.');

            return Command::FAILURE;
        }

        $doc = new \DOMDocument();
        $imported = $created = $skipped = 0;
        while ($reader->read()) {
            if (\XMLReader::ELEMENT !== $reader->nodeType || 1 !== $reader->depth) {
                continue;
            }

            // expand($doc) rattache le nœud à un document => simplexml l'accepte.
            $node = $reader->expand($doc);
            if (false === $node) {
                continue;
            }
            $xml = simplexml_import_dom($doc->importNode($node, true));
            if (false === $xml || null === $xml) {
                continue;
            }
            foreach (self::NS as $prefix => $uri) {
                $xml->registerXPathNamespace($prefix, $uri);
            }

            $raw = $this->toRawPublication($xml, $source->getCode());
            if (null === $raw) {
                ++$skipped;
                continue;
            }

            $result = $this->importer->import($raw, $source);
            ++$imported;
            $result->created && ++$created;

            if (0 === $imported % 50) {
                $this->em->flush();
                $io->writeln(\sprintf('  … %d importées (%d nouvelles)', $imported, $created));
            }
            if ($limit > 0 && $imported >= $limit) {
                break;
            }
        }
        $this->em->flush();
        $reader->close();

        $io->success(\sprintf('%d publication(s) traitée(s) — %d nouvelles, %d ignorées (sans titre).', $imported, $created, $skipped));
        $io->note('Suite : harvester:embed puis harvester:suggest-placement.');

        return Command::SUCCESS;
    }

    private function toRawPublication(\SimpleXMLElement $xml, string $sourceCode): ?RawPublication
    {
        // Seules les vraies références ont un z:itemType ; on écarte les journaux
        // (bib:Journal, sans itemType), pièces jointes et notes.
        $type = $this->first($xml, './/z:itemType');
        if (null === $type || \in_array(strtolower(trim($type)), ['attachment', 'note'], true)) {
            return null;
        }

        $title = $this->first($xml, './/dc:title');
        if (null === $title || '' === trim($title)) {
            return null;
        }

        $doi = $this->extractDoi($xml);
        $url = $this->extractUrl($xml);
        $abstract = $this->first($xml, './/dcterms:abstract') ?? $this->first($xml, './/dc:description');
        $venue = $this->first($xml, './/dcterms:isPartOf//dc:title');
        $date = $this->parseDate($this->first($xml, './/dc:date'));

        $authors = [];
        $seen = [];
        $i = 0;
        foreach ($xml->xpath('.//bib:authors//foaf:Person') ?: [] as $person) {
            foreach (self::NS as $p => $u) {
                $person->registerXPathNamespace($p, $u);
            }
            $surname = $this->first($person, './foaf:surname') ?? '';
            $given = $this->first($person, './foaf:givenName') ?? '';
            $name = trim($given.' '.$surname);
            $key = mb_strtolower($name);
            if ('' !== $name && !isset($seen[$key])) {
                $seen[$key] = true;
                $authors[] = new RawAuthor($name, null, null, $i++);
            }
        }

        $idInSource = $doi ?? ('zotero:'.substr(sha1($title), 0, 16));

        return new RawPublication(
            sourceCode: $sourceCode,
            idInSource: $idInSource,
            doi: $doi,
            title: trim($title),
            externalIds: [],
            abstract: null !== $abstract ? trim($abstract) : null,
            publicationDate: $date,
            language: null,
            venue: null !== $venue ? trim($venue) : null,
            type: null !== $type ? trim($type) : null,
            license: null,
            oaUrl: $url,
            fulltextAvailable: false,
            authors: $authors,
        );
    }

    private function extractDoi(\SimpleXMLElement $xml): ?string
    {
        foreach ($xml->xpath('.//dc:identifier') ?: [] as $id) {
            $text = trim((string) $id);
            if (preg_match('#10\.\d{4,9}/[^\s"<>]+#', $text, $m)) {
                return $m[0];
            }
        }
        $prism = $this->first($xml, './/prism:doi');

        return null !== $prism && '' !== trim($prism) ? trim($prism) : null;
    }

    private function extractUrl(\SimpleXMLElement $xml): ?string
    {
        foreach ($xml->xpath('.//dc:identifier//rdf:value') ?: [] as $v) {
            $text = trim((string) $v);
            if (str_starts_with($text, 'http')) {
                return $text;
            }
        }
        $about = (string) ($xml->attributes(self::NS['rdf'])['about'] ?? '');

        return str_starts_with($about, 'http') ? $about : null;
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return new \DateTimeImmutable($m[0]);
        }
        if (preg_match('/(19|20)\d{2}/', $value, $m)) {
            return new \DateTimeImmutable($m[0].'-01-01');
        }

        return null;
    }

    private function first(\SimpleXMLElement $xml, string $xpath): ?string
    {
        $nodes = $xml->xpath($xpath);

        return ($nodes && isset($nodes[0])) ? (string) $nodes[0] : null;
    }

    private function resolveSource(string $code): Source
    {
        $source = $this->sources->findOneByCode($code);
        if (null === $source) {
            $source = new Source($code, 'Zotero (import bibliothèque)', ApiType::Rest);
            $source->setPhase(2)->setActive(true);
            $this->em->persist($source);
            $this->em->flush();
        }

        return $source;
    }
}
