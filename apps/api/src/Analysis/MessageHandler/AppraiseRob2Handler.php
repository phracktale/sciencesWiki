<?php

declare(strict_types=1);

namespace App\Analysis\MessageHandler;

use App\Analysis\Message\AppraiseRob2Message;
use App\Analysis\Rob2\Rob2Appraiser;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Évalue RoB 2 une publication en asynchrone (worker « analysis »). Le marqueur
 * rob2_appraising_at (posé au dispatch) est TOUJOURS levé en fin de traitement pour
 * ne jamais laisser le loader de l'outil bloqué. reappraise=false : réutilise une
 * évaluation existante.
 */
#[AsMessageHandler]
final class AppraiseRob2Handler
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly Rob2Appraiser $appraiser,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(AppraiseRob2Message $message): void
    {
        $publication = $this->publications->find($message->publicationId);
        if (null === $publication) {
            return;
        }

        try {
            $this->appraiser->appraiseForPublication($publication, null, false);
        } finally {
            $publication->setRob2AppraisingAt(null);
            $this->em->flush();
        }
    }
}
