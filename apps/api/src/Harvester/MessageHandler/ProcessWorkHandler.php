<?php

declare(strict_types=1);

namespace App\Harvester\MessageHandler;

use App\Harvester\Connector\ConnectorRegistry;
use App\Harvester\Message\ProcessWork;
use App\Harvester\Pipeline\PublicationImporter;
use App\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Traite un travail découvert : récupère ses métadonnées, le normalise, le
 * dédoublonne et le persiste (cf. Phase 1 §4, étapes B–E). Idempotent.
 */
#[AsMessageHandler]
final class ProcessWorkHandler
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly SourceRepository $sources,
        private readonly PublicationImporter $importer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProcessWork $message): void
    {
        $ref = $message->ref;
        $source = $this->sources->findOneByCode($ref->sourceCode);
        if (null === $source) {
            throw new \RuntimeException(\sprintf('Source inconnue : « %s ».', $ref->sourceCode));
        }

        $raw = $this->connectors->get($ref->sourceCode)->fetchMetadata($ref);
        $this->importer->import($raw, $source);
        $this->em->flush();
    }
}
