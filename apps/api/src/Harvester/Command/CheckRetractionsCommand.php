<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Enum\RetractionStatus;
use App\Service\ActivityLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Vérifie les rétractations / mises en garde (Expression of Concern) des
 * publications référencées, en croisant leurs DOI avec le jeu de données
 * Retraction Watch (distribué par Crossref). À lancer mensuellement (cron).
 *
 *   bin/console app:retractions:check
 *
 * Les études signalées sont exclues de la rédaction RAG (cf. PublicationRepository).
 * Les réponses déjà validées par un humain qui citaient une source nouvellement
 * signalée sont marquées « à revalider » (bandeau public).
 */
#[AsCommand(name: 'app:retractions:check', description: 'Détecte les publications rétractées / sous mise en garde (Retraction Watch).')]
final class CheckRetractionsCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
        private readonly HttpClientInterface $httpClient,
        private readonly ActivityLogger $activity,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('url', null, InputOption::VALUE_REQUIRED, 'URL du CSV Retraction Watch (défaut : API Crossref Labs).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = (string) ($input->getOption('url') ?: 'https://api.labs.crossref.org/data/retractionwatch?mailto='.rawurlencode($this->contactEmail));

        // Nos DOI connus (normalisés en minuscules) → id publication.
        $ours = [];
        foreach ($this->conn->executeQuery("SELECT id, lower(doi) AS doi FROM publication WHERE doi IS NOT NULL AND doi <> ''")->fetchAllAssociative() as $row) {
            $ours[(string) $row['doi']] = (int) $row['id'];
        }
        if ([] === $ours) {
            $io->warning('Aucune publication avec DOI.');

            return Command::SUCCESS;
        }
        $io->writeln(\sprintf('%d publications avec DOI à vérifier.', \count($ours)));

        // Téléchargement du jeu de données Retraction Watch.
        try {
            $csv = $this->httpClient->request('GET', $url, ['timeout' => 120])->getContent();
        } catch (\Throwable $e) {
            $io->error('Téléchargement Retraction Watch impossible : '.$e->getMessage());

            return Command::FAILURE;
        }

        $updated = ['retracted' => 0, 'concern' => 0];
        $flaggedIds = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream);
        if (false === $header) {
            $io->error('CSV Retraction Watch illisible.');

            return Command::FAILURE;
        }
        $col = array_flip(array_map('trim', $header));
        $doiCol = $col['OriginalPaperDOI'] ?? null;
        $natureCol = $col['RetractionNature'] ?? null;
        if (null === $doiCol || null === $natureCol) {
            $io->error('Colonnes attendues absentes (OriginalPaperDOI / RetractionNature).');

            return Command::FAILURE;
        }

        while (false !== ($r = fgetcsv($stream))) {
            $doi = strtolower(trim((string) ($r[$doiCol] ?? '')));
            $doi = preg_replace('#^https?://(dx\.)?doi\.org/#', '', $doi) ?? $doi;
            if ('' === $doi || !isset($ours[$doi])) {
                continue;
            }
            $nature = strtolower((string) ($r[$natureCol] ?? ''));
            $status = str_contains($nature, 'concern') ? RetractionStatus::Concern : RetractionStatus::Retracted;

            $this->conn->executeStatement(
                'UPDATE publication SET retraction_status = :s, retraction_checked_at = now() WHERE id = :id AND retraction_status <> :s',
                ['s' => $status->value, 'id' => $ours[$doi]],
            );
            ++$updated[$status->value];
            $flaggedIds[] = $ours[$doi];
        }
        fclose($stream);

        // Horodate la vérification pour toutes les publications (même non signalées).
        $this->conn->executeStatement('UPDATE publication SET retraction_checked_at = now() WHERE doi IS NOT NULL');

        // Réponses validées par un humain citant une source nouvellement signalée
        // → à revalider (bandeau public). Détection via les notes de bas de page.
        $revalidated = 0;
        if ([] !== $flaggedIds) {
            $revalidated = $this->flagAffectedAnswers($flaggedIds);
        }

        $summary = \sprintf('%d rétractées, %d mises en garde ; %d réponse(s) validée(s) à revalider.', $updated['retracted'], $updated['concern'], $revalidated);
        $io->success($summary);
        $this->activity->log('settings', 'retraction_check', 'system', 'Vérification rétractations : '.$summary, $updated);

        return Command::SUCCESS;
    }

    /**
     * Marque « à revalider » les réponses validées par un humain dont une source
     * (note de bas de page) vient d'être signalée. Renvoie le nombre de réponses.
     *
     * @param list<int> $publicationIds
     */
    private function flagAffectedAnswers(array $publicationIds): int
    {
        // La table answer porte needs_revalidation (cf. migration). Le lien
        // réponse→publication passe par footnote → answer_revision → answer.
        try {
            return (int) $this->conn->executeStatement(
                "UPDATE answer SET needs_revalidation = true
                 WHERE validation_status = 'valide' AND id IN (
                    SELECT ar.answer_id FROM answer_revision ar
                    JOIN footnote f ON f.answer_revision_id = ar.id
                    WHERE f.publication_id IN (:ids)
                 )",
                ['ids' => $publicationIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return 0; // schéma footnote différent : on ne bloque pas la vérification
        }
    }
}
