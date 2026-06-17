<?php

declare(strict_types=1);

namespace App\Harvester;

use App\Entity\IngestionJob;
use App\Entity\Source;
use App\Harvester\Connector\ConnectorRegistry;
use App\Harvester\Dto\DiscoveryCursor;
use App\Harvester\Message\ProcessWork;
use App\Harvester\Pipeline\PublicationImporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Orchestre une exécution de moisson pour une source : découverte → traitement,
 * en tenant à jour un {@see IngestionJob} (compteurs, curseur de reprise).
 *
 * Deux modes :
 *  - inline (défaut) : traite chaque travail immédiatement (compteurs exacts) ;
 *  - async : publie un message {@see ProcessWork} par travail (cf. Phase 1 §4).
 */
final class HarvestRunner
{
    private const FLUSH_EVERY = 50;

    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly PublicationImporter $importer,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $query
     */
    public function run(Source $source, DiscoveryCursor $cursor, bool $async, array $query): IngestionJob
    {
        $connector = $this->connectors->get($source->getCode());

        $job = new IngestionJob($source, $query);
        $this->em->persist($job);
        $this->em->flush();

        try {
            foreach ($connector->discover($cursor) as $ref) {
                $job->countProcessed();

                try {
                    if ($async) {
                        $this->bus->dispatch(new ProcessWork($ref));
                    } else {
                        $raw = $connector->fetchMetadata($ref);
                        $result = $this->importer->import($raw, $source);
                        if ($result->created) {
                            $job->countCreated();
                        }
                    }
                } catch (\Throwable $e) {
                    $job->countError();
                    $this->logger->error('Échec de traitement d\'un travail moissonné.', [
                        'source' => $source->getCode(),
                        'id' => $ref->idInSource,
                        'exception' => $e,
                    ]);
                }

                if (0 === $job->getProcessed() % self::FLUSH_EVERY) {
                    $this->em->flush();
                }
            }

            $job->setEndCursor($connector->getLastCursor());
            $job->finish();
        } catch (\Throwable $e) {
            $job->fail($e->getMessage());
            $this->logger->error('Échec de la moisson : '.$e->getMessage(), ['source' => $source->getCode(), 'exception' => $e]);
        }

        $this->em->flush();

        return $job;
    }
}
