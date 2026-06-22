<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publie un « tick » Mercure sur le topic de la moisson à chaque évènement
 * (job terminé, ingestion plein texte…). Le client (barre admin) ré-interroge
 * alors le statut → mise à jour quasi temps réel. Payload minimal (pas de données
 * sensibles : le détail reste derrière l'API admin authentifiée). Tolérant aux
 * pannes : si le hub est indisponible, on n'interrompt pas le traitement.
 */
final class HarvestTicker
{
    public const TOPIC = 'sciences/harvest';

    public function __construct(private readonly HubInterface $hub)
    {
    }

    public function tick(string $kind = 'update'): void
    {
        try {
            $this->hub->publish(new Update(self::TOPIC, json_encode(['kind' => $kind], \JSON_THROW_ON_ERROR)));
        } catch (\Throwable) {
            // Hub indisponible : on ignore (le polling 5 s reste le filet).
        }
    }
}
