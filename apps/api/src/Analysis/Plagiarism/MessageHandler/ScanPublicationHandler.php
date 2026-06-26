<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism\MessageHandler;

use App\Analysis\Plagiarism\Message\ScanPublication;
use App\Analysis\Plagiarism\PlagiarismScanner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Détection asynchrone des doublons/plagiat pour une publication (cf. docs/spec-plagiat.md §7).
 */
#[AsMessageHandler]
final class ScanPublicationHandler
{
    public function __construct(private readonly PlagiarismScanner $scanner)
    {
    }

    public function __invoke(ScanPublication $message): void
    {
        $this->scanner->scan($message->publicationId);
    }
}
