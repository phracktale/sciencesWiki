<?php

declare(strict_types=1);

namespace App\Analysis\MessageHandler;

use App\Analysis\Amstar2\Amstar2Appraiser;
use App\Analysis\Message\AppraiseAmstar2Message;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Évalue AMSTAR-2 une publication en asynchrone. Le marqueur amstar2_appraising_at
 * (posé au dispatch) est TOUJOURS levé en fin de traitement.
 */
#[AsMessageHandler]
final class AppraiseAmstar2Handler
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly Amstar2Appraiser $appraiser,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(AppraiseAmstar2Message $message): void
    {
        $publication = $this->publications->find($message->publicationId);
        if (null === $publication) {
            return;
        }

        try {
            $this->appraiser->appraiseForPublication($publication, null, false);
        } finally {
            $publication->setAmstar2AppraisingAt(null);
            $this->em->flush();
        }
    }
}
