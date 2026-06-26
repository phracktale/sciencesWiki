<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism\MessageHandler;

use App\Analysis\Plagiarism\Message\FingerprintPublication;
use App\Analysis\Plagiarism\Message\ScanPublication;
use App\Analysis\Plagiarism\PublicationFingerprinter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Empreinte une publication puis enchaîne (optionnel) sur sa détection — même code
 * que la CLI `app:plagiarism:fingerprint`/`:scan` (cf. docs/spec-plagiat.md §7).
 */
#[AsMessageHandler]
final class FingerprintPublicationHandler
{
    public function __construct(
        private readonly PublicationFingerprinter $fingerprinter,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(FingerprintPublication $message): void
    {
        $this->fingerprinter->fingerprint($message->publicationId);
        if ($message->thenScan) {
            $this->bus->dispatch(new ScanPublication($message->publicationId));
        }
    }
}
