<?php

declare(strict_types=1);

namespace App\Analysis\MessageHandler;

use App\Analysis\AnalysisOptions;
use App\Analysis\AnalysisOrchestrator;
use App\Analysis\Message\AnalyzeNodeMessage;
use App\Repository\TreeNodeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Exécute l'orchestrateur d'analyse pour un nœud, en asynchrone (cf. spec §7bis).
 * Même orchestrateur que la CLI `analysis:run` — aucun chemin de code dupliqué.
 */
#[AsMessageHandler]
final class AnalyzeNodeHandler
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly AnalysisOrchestrator $orchestrator,
    ) {
    }

    public function __invoke(AnalyzeNodeMessage $message): void
    {
        $node = $this->nodes->find($message->nodeId);
        if (null === $node) {
            throw new \RuntimeException(\sprintf('Nœud introuvable (id %d).', $message->nodeId));
        }

        $this->orchestrator->run($node, new AnalysisOptions(
            reextract: $message->reextract,
            force: $message->force,
            openalex: $message->openalex,
        ));
    }
}
