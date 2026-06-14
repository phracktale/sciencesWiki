<?php

declare(strict_types=1);

namespace App\Harvester\Dto;

/**
 * Référence brute d'un travail à traiter, émise pendant la découverte.
 *
 * Peut transporter la charge utile complète (`payload`) quand la source renvoie
 * déjà l'objet entier lors de la pagination (cas d'OpenAlex), pour éviter un
 * second appel réseau lors de la récupération des métadonnées.
 */
final class RawRef
{
    /**
     * @param array<string,mixed>|null $payload
     */
    public function __construct(
        public readonly string $sourceCode,
        public readonly string $idInSource,
        public readonly ?string $doi = null,
        public readonly ?array $payload = null,
    ) {
    }
}
