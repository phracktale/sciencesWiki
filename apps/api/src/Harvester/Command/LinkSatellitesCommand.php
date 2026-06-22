<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Catalog\PublicationType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Rattrapage du lien « satellite → article parent » pour les objets rattachés
 * déjà en base (erratum, rapport de relecture, rétractation, annexes…).
 *
 * Source du lien :
 *   1) Crossref `relation` (is-review-of / is-correction-of / updates / …) — fiable
 *      quand l'éditeur l'a déposé (surtout les peer-reviews) ;
 *   2) repli heuristique sur le TITRE (« Erratum: <titre parent> », « Correction
 *      to: … », « Referee report. For: … ») rapproché d'un article du corpus.
 *
 * Renseigne publication.parent_doi (+ parent_publication_id si le parent est en
 * base). Idempotent et borné (ne reprend que les satellites sans parent_doi).
 *
 *   bin/console app:satellites:link --limit=300
 */
#[AsCommand(name: 'app:satellites:link', description: 'Relie les satellites (erratum, peer-review…) à leur article parent (Crossref + titre).')]
final class LinkSatellitesCommand extends Command
{
    /** Préfixes de titre → on isole le titre du parent (minuscules). */
    private const TITLE_PREFIXES = [
        'erratum to: ', 'erratum to ', 'erratum: ', 'erratum ',
        'corrigendum to: ', 'corrigendum to ', 'corrigendum: ', 'corrigendum ',
        'author correction to: ', 'author correction: ', 'publisher correction to: ', 'publisher correction: ',
        'correction to: ', 'correction to ', 'correction: ',
        'retraction note to: ', 'retraction note: ', 'retraction: ', 'retraction of ',
        'comment on: ', 'comment on ',
        'referee report. for: ', 'referee report for: ', 'review of: ',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de satellites à traiter', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $conn = $this->em->getConnection();

        $rows = $conn->executeQuery(
            'SELECT id, doi, title FROM publication
              WHERE type IN (:types) AND parent_doi IS NULL
              ORDER BY id
              LIMIT '.$limit,
            ['types' => PublicationType::ATTACHED],
            ['types' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        if ([] === $rows) {
            $io->success('Aucun satellite à relier (tous traités).');

            return Command::SUCCESS;
        }

        $viaCrossref = 0;
        $viaTitle = 0;
        $resolved = 0;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $parentDoi = null;
            $parentId = null;

            // 1) Crossref relation (si DOI présent).
            $doi = $this->normalizeDoi((string) ($r['doi'] ?? ''));
            if ('' !== $doi) {
                $parentDoi = $this->crossrefParentDoi($doi);
                if (null !== $parentDoi) {
                    ++$viaCrossref;
                    $parentId = $this->findByDoi($conn, $parentDoi);
                }
                usleep(70_000); // pool poli Crossref
            }

            // 2) Repli sur le titre → on cherche un article du corpus.
            if (null === $parentDoi) {
                $found = $this->matchByTitle($conn, (string) ($r['title'] ?? ''));
                if (null !== $found) {
                    ++$viaTitle;
                    $parentId = $found['id'];
                    $parentDoi = $found['doi'] ?: ('local:'.$found['id']);
                }
            }

            if (null === $parentDoi) {
                // Marque comme « traité, sans parent trouvé » pour ne pas le reprendre.
                $conn->executeStatement('UPDATE publication SET parent_doi = :p WHERE id = :id', ['p' => '', 'id' => $id]);
                continue;
            }

            if (null !== $parentId) {
                ++$resolved;
            }
            $conn->executeStatement(
                'UPDATE publication SET parent_doi = :p, parent_publication_id = :pid WHERE id = :id',
                ['p' => $parentDoi, 'pid' => $parentId, 'id' => $id],
            );
        }

        $io->success(\sprintf(
            '%d satellite(s) traité(s) : %d via Crossref, %d via titre, %d rattachés à un article en base.',
            \count($rows), $viaCrossref, $viaTitle, $resolved,
        ));

        return Command::SUCCESS;
    }

    /** DOI nu, minuscule (sans préfixe doi.org). */
    private function normalizeDoi(string $doi): string
    {
        $doi = trim($doi);
        $doi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi) ?? $doi;

        return mb_strtolower(trim($doi));
    }

    /** Interroge Crossref et renvoie le DOI parent (nu) si une relation l'indique. */
    private function crossrefParentDoi(string $bareDoi): ?string
    {
        try {
            $resp = $this->httpClient->request('GET', 'https://api.crossref.org/works/'.$bareDoi, [
                'query' => ['mailto' => $this->contactEmail],
                'timeout' => 15,
            ]);
            if (200 !== $resp->getStatusCode()) {
                return null;
            }
            $relation = $resp->toArray(false)['message']['relation'] ?? [];
        } catch (\Throwable) {
            return null;
        }

        foreach (['is-review-of', 'is-correction-of', 'is-update-to', 'updates', 'is-comment-on', 'is-retraction-of'] as $key) {
            foreach ($relation[$key] ?? [] as $rel) {
                if ('doi' === ($rel['id-type'] ?? '') && '' !== (string) ($rel['id'] ?? '')) {
                    return $this->normalizeDoi((string) $rel['id']);
                }
            }
        }

        return null;
    }

    /** Résout un DOI nu vers un id de publication local (formats nu / doi.org). */
    private function findByDoi(\Doctrine\DBAL\Connection $conn, string $bareDoi): ?int
    {
        $id = $conn->executeQuery(
            "SELECT id FROM publication WHERE lower(doi) IN (:a, :b) LIMIT 1",
            ['a' => $bareDoi, 'b' => 'https://doi.org/'.$bareDoi],
        )->fetchOne();

        return false === $id ? null : (int) $id;
    }

    /**
     * Repli : isole le titre du parent d'après le préfixe et cherche un article
     * (non-satellite) au titre identique.
     *
     * @return array{id:int,doi:?string}|null
     */
    private function matchByTitle(\Doctrine\DBAL\Connection $conn, string $title): ?array
    {
        $t = mb_strtolower(trim($title));
        if ('' === $t) {
            return null;
        }
        $parentTitle = null;
        foreach (self::TITLE_PREFIXES as $prefix) {
            if (str_starts_with($t, $prefix)) {
                $parentTitle = trim(mb_substr($title, mb_strlen($prefix)));
                break;
            }
        }
        if (null === $parentTitle || mb_strlen($parentTitle) < 12) {
            return null; // trop court → risque de faux positif
        }
        // Nettoie une éventuelle référence bibliographique entre crochets en fin.
        $parentTitle = trim(preg_replace('#\s*\[[^\]]*\]\s*$#', '', $parentTitle) ?? $parentTitle);

        $row = $conn->executeQuery(
            'SELECT id, doi FROM publication
              WHERE lower(title) = lower(:t) AND '.PublicationType::notSatelliteSql().'
              ORDER BY id LIMIT 1',
            ['t' => $parentTitle],
        )->fetchAssociative();

        return false === $row ? null : ['id' => (int) $row['id'], 'doi' => $row['doi'] ?? null];
    }
}
