<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Nature d'un rapprochement de contenu entre deux publications (cf. spec-plagiat.md §4.3).
 * NON DÉCISIONNEL : décrit le signal, pas un verdict.
 */
enum DuplicationType: string
{
    case NearDuplicate = 'near_duplicate';      // recouvrement global très élevé
    case VerbatimOverlap = 'verbatim_overlap';  // passages copiés mot à mot
    case Paraphrase = 'paraphrase';             // sémantique élevée, verbatim faible
    case SelfOverlap = 'self_overlap';          // idem mais auteur commun (auto-plagiat)

    public function label(): string
    {
        return match ($this) {
            self::NearDuplicate => 'Quasi-doublon',
            self::VerbatimOverlap => 'Recouvrement verbatim',
            self::Paraphrase => 'Paraphrase',
            self::SelfOverlap => 'Auto-recouvrement',
        };
    }
}
