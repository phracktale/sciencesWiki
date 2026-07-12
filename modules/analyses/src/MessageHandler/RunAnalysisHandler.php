<?php

declare(strict_types=1);

namespace Analyses\MessageHandler;

use Analyses\Analyzer\AnalysisOrchestrator;
use Analyses\Message\RunAnalysisMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Ulid;

#[AsMessageHandler]
final class RunAnalysisHandler
{
    public function __construct(private readonly AnalysisOrchestrator $orchestrator)
    {
    }

    public function __invoke(RunAnalysisMessage $message): void
    {
        if (Ulid::isValid($message->assessmentId)) {
            $this->orchestrator->process(Ulid::fromString($message->assessmentId));
        }
    }
}
