<?php

declare(strict_types=1);

namespace App\Rag\Message;

/**
 * Demande de RÉDACTION d'un article de vulgarisation à la demande (pipeline 2 appels :
 * extraction de faits → rédaction). Traitée en asynchrone (worker « article ») car
 * lente ; l'utilisateur est averti par e-mail à la fin (lien + PDF joint) si un e-mail
 * a été fourni.
 */
final class GenerateArticleMessage
{
    public function __construct(
        public readonly int $questionId,
        public readonly ?string $notifyEmail = null,
    ) {
    }
}
