<?php

declare(strict_types=1);

namespace App\Harvester\MessageHandler;

use App\Harvester\Ai\FulltextIngester;
use App\Harvester\Message\IngestFulltext;
use App\Repository\PublicationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ingestion du texte intégral d'une publication (PDF éditeur → GROBID → vecteurs).
 * Exécuté en parallèle par le pool de workers « fulltext ». GROBID (Marvin) gère
 * la concurrence ; le débit total = nb de workers × ~1 article / ~20 s.
 */
#[AsMessageHandler]
final class IngestFulltextHandler
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly FulltextIngester $fulltext,
    ) {
    }

    public function __invoke(IngestFulltext $message): void
    {
        $publication = $this->publications->find($message->publicationId);
        if (null !== $publication) {
            $this->fulltext->ingest($publication);
        }
    }
}
