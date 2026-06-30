<?php

declare(strict_types=1);

namespace App\Analysis\MessageHandler;

use App\Analysis\Appraisal\StudyDesignClassifier;
use App\Analysis\Message\ClassifyStudyMessage;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Détecte le devis d'une publication (→ outils applicables) en asynchrone, puis
 * persiste. Idempotent : si déjà classée, ne refait rien (le dispatch est déjà
 * conditionné côté contrôleur, mais on protège des re-livraisons).
 */
#[AsMessageHandler]
final class ClassifyStudyHandler
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly StudyDesignClassifier $classifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ClassifyStudyMessage $message): void
    {
        $publication = $this->publications->find($message->publicationId);
        if (null === $publication || null !== $publication->getClassifiedAt()) {
            return;
        }
        $this->classifier->classify($publication);
        $this->em->flush();
    }
}
