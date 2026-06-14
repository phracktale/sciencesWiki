<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

use App\Entity\Publication;

/**
 * Calcule et stocke l'embedding d'une publication (titre + résumé).
 */
final class PublicationEmbedder
{
    private const MAX_CHARS = 4000;

    private readonly EmbeddingClient $client;

    public function __construct(EmbeddingClientFactory $factory)
    {
        $this->client = $factory->create();
    }

    public function embed(Publication $publication): void
    {
        $publication->setEmbedding($this->client->embed($this->text($publication)));
        $publication->touch();
    }

    private function text(Publication $publication): string
    {
        $text = trim($publication->getTitle()."\n\n".($publication->getAbstract() ?? ''));

        return mb_substr($text, 0, self::MAX_CHARS);
    }
}
