<?php

declare(strict_types=1);

namespace App\Harvester\MessageHandler;

use App\Harvester\Ai\PublicationEmbedder;
use App\Harvester\Ai\PlacementSuggester;
use App\Harvester\Dto\DiscoveryCursor;
use App\Harvester\HarvestRunner;
use App\Harvester\Message\HarvestRubric;
use App\Repository\IngestionJobRepository;
use App\Repository\PublicationRepository;
use App\Repository\SourceRepository;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Moisson ciblée d'une rubrique : récupère d'OpenAlex les travaux dont le
 * concept primaire correspond à la rubrique (incrémental via reprise du curseur
 * de pagination), les importe (dédup DOI), calcule les embeddings et propose
 * leur placement. Borné par exécution ; re-déclencher reprend là où on s'était arrêté.
 */
#[AsMessageHandler]
final class HarvestRubricHandler
{
    /** Articles OA dont on tente le texte intégral par exécution (borne la charge). */
    private const MAX_FULLTEXT_PER_RUN = 20;

    /** Plafond d'enrichissement (embeddings/placement) par exécution, pour la mémoire. */
    private const MAX_ENRICH_PER_RUN = 3000;

    /** Niveau du nœud → segment de filtre OpenAlex « primary_topic.X.id ». */
    private const FILTER_KEY = [0 => 'domain', 1 => 'field', 2 => 'subfield'];

    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly SourceRepository $sources,
        private readonly HarvestRunner $runner,
        private readonly PublicationRepository $publications,
        private readonly PublicationEmbedder $embedder,
        private readonly \App\Harvester\Ai\FulltextIngester $fulltext,
        private readonly PlacementSuggester $suggester,
        private readonly IngestionJobRepository $jobs,
        private readonly \App\Harvester\Connector\OpenAlex\OpenAlexConnector $openalex,
        private readonly \App\Harvester\OpenAlexThrottle $throttle,
        private readonly \App\Service\ActivityLogger $activity,
        private readonly \App\Service\SettingsService $settings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(HarvestRubric $message): void
    {
        $node = $this->nodes->find($message->nodeId);
        $concept = $node?->getOpenalexConceptId();
        if (null === $node || null === $concept) {
            return;
        }
        $source = $this->sources->findOneByCode('openalex');
        if (null === $source) {
            return;
        }

        // Stratégie de moisson librement paramétrable en back-office.
        $maxPerRun = $this->settings->harvestMaxPerRun();
        $cap = $this->settings->harvestCapPerRubric();
        $sort = $this->settings->harvestSort();
        $recentYears = $this->settings->harvestRecentYears();

        // Plafond par rubrique : si déjà atteint, on n'inonde pas davantage.
        if ($cap > 0 && $this->jobs->sumProcessedForRubric($node->getSlug()) >= $cap) {
            $this->activity->log('harvest', 'harvest_capped', 'worker', \sprintf('Plafond de %d atteint pour « %s » — moisson ignorée.', $cap, $node->getLabel()), ['rubric' => $node->getSlug()]);

            return;
        }

        // Filtre concept : primary_topic.{domain|field|subfield}.id:<concept>,
        // ou primary_topic.id:<concept> pour un topic (niveau 3).
        $key = self::FILTER_KEY[$node->getLevel()] ?? null;
        $filter = null !== $key ? 'primary_topic.'.$key.'.id:'.$concept : 'primary_topic.id:'.$concept;

        // Fenêtre de récence (paramétrable) : ne garder que les N dernières années.
        if ($recentYears > 0) {
            $from = (new \DateTimeImmutable())->modify('-'.$recentYears.' years')->format('Y-m-d');
            $filter .= ',from_publication_date:'.$from;
        }

        // Incrémental SANS `from_updated_date` (filtre payant) : reprise du curseur
        // de pagination de la dernière exécution réussie ; dédup DOI contre les recouvrements.
        $resume = $this->jobs->findResumeCursorForRubric($node->getSlug());

        // Volume total disponible chez OpenAlex (meta.count) → permet de savoir ce
        // qu'il reste à moissonner. Non bloquant si la requête échoue.
        try {
            $total = $this->openalex->countWorks($filter);
            if (null !== $total) {
                $this->throttle->recordRubricTotal($node->getSlug(), $total);
            }
        } catch (\Throwable) {
            // ignore : le comptage est purement informatif
        }

        $cursor = new DiscoveryCursor(
            cursor: $resume,
            maxRecords: $maxPerRun,
            filter: $filter,
            sort: '' !== $sort ? $sort : null,
        );

        $job = $this->runner->run($source, $cursor, false, ['rubric' => $node->getSlug(), 'filter' => $filter]);
        $this->logger->info('Moisson rubrique', ['rubric' => $node->getSlug(), 'created' => $job->getCreated(), 'processed' => $job->getProcessed()]);

        // Enrichissement des nouvelles publications : embeddings PAR LOTS (un seul
        // appel au service ml/ pour ~64 publications → bien plus rapide qu'unitaire).
        // Borné pour maîtriser la mémoire ; le reliquat est repris au prochain run.
        $enrichLimit = min(max(1, $maxPerRun) * 2, self::MAX_ENRICH_PER_RUN);
        $needing = $this->publications->findNeedingEmbedding($enrichLimit);
        foreach (array_chunk($needing, 64) as $batch) {
            try {
                $this->embedder->embedMany($batch);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->warning('Lot d\'embeddings échoué : '.$e->getMessage());
            }
        }

        foreach ($this->publications->findNeedingPlacement($enrichLimit) as $publication) {
            $this->suggester->suggest($publication, 3);
        }
        $this->em->flush();

        // Texte intégral des publications en accès libre : téléchargement du PDF
        // sur le site de l'éditeur/dépôt, extraction et vectorisation par fragments
        // (borné par exécution pour ne pas surcharger l'inférence d'embeddings).
        $fulltextChunks = 0;
        foreach ($this->publications->findNeedingFulltext(self::MAX_FULLTEXT_PER_RUN) as $publication) {
            $fulltextChunks += $this->fulltext->ingest($publication);
        }

        $node->markHarvested();
        $this->em->flush();

        // Journal d'audit : historique des moissons (volume, durée, statut).
        $duration = $job->getFinishedAt()?->getTimestamp() - $job->getStartedAt()->getTimestamp();
        $this->activity->log(
            'harvest',
            'harvest_run',
            'worker',
            \sprintf('Moisson « %s » : %d nouveaux / %d traités (%s), %d fragments texte intégral, %d s.', $node->getLabel(), $job->getCreated(), $job->getProcessed(), $job->getStatus()->value, $fulltextChunks, (int) $duration),
            [
                'rubric' => $node->getSlug(),
                'created' => $job->getCreated(),
                'processed' => $job->getProcessed(),
                'errors' => $job->getErrors(),
                'status' => $job->getStatus()->value,
                'fulltextChunks' => $fulltextChunks,
                'durationSeconds' => (int) $duration,
            ],
        );
    }
}
