<?php

declare(strict_types=1);

namespace App\Harvester\MessageHandler;

use App\Harvester\Command\GenerateWikiArticlesCommand;
use App\Harvester\Message\GenerateNodeArticle;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * (Re)génère l'article d'une rubrique à la demande (bouton admin du wiki public),
 * en appelant EN PROCESS la même logique que le cron (GenerateWikiArticlesCommand::
 * generateOne) — aucune duplication, aucun sous-processus. Le marqueur
 * article_generating_at (posé au dispatch) est TOUJOURS levé en fin de traitement
 * (succès comme échec) pour ne jamais laisser le loader public bloqué.
 */
#[AsMessageHandler]
final class GenerateNodeArticleHandler
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
        private readonly GenerateWikiArticlesCommand $generator,
    ) {
    }

    public function __invoke(GenerateNodeArticle $message): void
    {
        $node = $this->nodes->find($message->nodeId);
        if (null === $node) {
            return;
        }

        try {
            $this->generator->generateOne($node);
        } finally {
            // Toujours lever le marqueur « en cours » : generateOne a pu échouer,
            // renvoyer un article trop court, ou lever une exception (re-livraison).
            $node->setArticleGeneratingAt(null);
            $this->em->flush();
        }
    }
}
