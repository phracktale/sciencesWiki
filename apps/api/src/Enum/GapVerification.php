<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Résultat de la vérification croisée d'une piste hors de son nœud d'origine
 * (cf. docs/spec-controverses-lacunes.md §4.2 / §6.5).
 */
enum GapVerification: string
{
    case Unverified = 'unverified';     // pas encore confronté ailleurs
    case Unexplored = 'unexplored';     // introuvable ailleurs → vraie piste
    case Corroborated = 'corroborated'; // testée ailleurs, même conclusion → renforce
    case Contested = 'contested';       // testée ailleurs, conclusion divergente → encart
}
