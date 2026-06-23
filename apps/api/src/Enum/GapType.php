<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Nature d'une piste inexplorée (cf. docs/spec-controverses-lacunes.md §4.2 / §6).
 */
enum GapType: string
{
    case MissingLink = 'missing_link';   // Swanson A–C (chaînon manquant)
    case SparseCell = 'sparse_cell';     // case creuse variable × population × méthode
    case SelfDeclared = 'self_declared'; // lacune réclamée par les auteurs
}
