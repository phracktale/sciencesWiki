<?php

declare(strict_types=1);

namespace App\Analysis\MessageHandler;

use App\Analysis\Axis\AxisAppraiser;
use App\Analysis\Message\AppraisePublicationMessage;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Évalue AXIS une publication en asynchrone (worker « analysis »), en appelant la
 * MÊME logique que le cron/comité (AxisAppraiser::appraiseForPublication) — aucune
 * duplication. Le marqueur axis_appraising_at (posé au dispatch) est TOUJOURS levé
 * en fin de traitement (succès, étude non évaluable, ou exception) pour ne jamais
 * laisser le loader de l'outil bloqué. reappraise=false : réutilise une évaluation
 * existante (instantané) ; sinon l'appel LLM crée l'AxisAppraisal récupéré par polling.
 */
#[AsMessageHandler]
final class AppraisePublicationHandler
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly AxisAppraiser $appraiser,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(AppraisePublicationMessage $message): void
    {
        $publication = $this->publications->find($message->publicationId);
        if (null === $publication) {
            return;
        }

        try {
            $this->appraiser->appraiseForPublication($publication, null, $message->reappraise);
        } finally {
            $publication->setAxisAppraisingAt(null);
            $this->em->flush();
        }
    }
}
