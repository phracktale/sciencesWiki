<?php

declare(strict_types=1);

namespace App\Analysis\MessageHandler;

use App\Analysis\Message\AppraiseMmatMessage;
use App\Analysis\Mmat\MmatAppraiser;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Évalue MMAT une publication en asynchrone. Le marqueur mmat_appraising_at (posé au
 * dispatch) est TOUJOURS levé en fin de traitement.
 */
#[AsMessageHandler]
final class AppraiseMmatHandler
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly MmatAppraiser $appraiser,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(AppraiseMmatMessage $message): void
    {
        $publication = $this->publications->find($message->publicationId);
        if (null === $publication) {
            return;
        }

        try {
            $this->appraiser->appraiseForPublication($publication, null, false);
        } finally {
            $publication->setMmatAppraisingAt(null);
            $this->em->flush();
        }
    }
}
