<?php

declare(strict_types=1);

namespace App\Harvester\MessageHandler;

use App\Harvester\Command\GenerateWikiArticlesCommand;
use App\Harvester\Message\GenerateNodeArticle;
use App\Repository\TreeNodeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * (Re)génère l'article d'une rubrique à la demande (bouton admin du wiki public),
 * en appelant EN PROCESS la même logique que le cron (GenerateWikiArticlesCommand::
 * generateOne) — aucune duplication, aucun sous-processus.
 */
#[AsMessageHandler]
final class GenerateNodeArticleHandler
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly GenerateWikiArticlesCommand $generator,
    ) {
    }

    public function __invoke(GenerateNodeArticle $message): void
    {
        $node = $this->nodes->find($message->nodeId);
        if (null === $node) {
            return;
        }

        $this->generator->generateOne($node);
    }
}
