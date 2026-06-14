<?php

declare(strict_types=1);

namespace App\Harvester\MessageHandler;

use App\Harvester\Message\ResolveOpenAccess;
use App\Harvester\Oa\OaEnricher;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Résout l'accès ouvert d'une publication via Unpaywall (cf. Phase 1 §4, étape C).
 */
#[AsMessageHandler]
final class ResolveOpenAccessHandler
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly OaEnricher $enricher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ResolveOpenAccess $message): void
    {
        $publication = $this->publications->find($message->publicationId);
        if (null === $publication) {
            return;
        }

        $this->enricher->enrich($publication);
        $this->em->flush();
    }
}
