<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Publication;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Contrôle d'accès aux études pour les outils d'évaluation. Une publication déposée
 * par un utilisateur (submittedBy != null) et non encore intégrée au corpus
 * (listed_in_corpus=false) est PRIVÉE : seul son uploadeur peut la manipuler.
 * Les publications du corpus public restent accessibles à tous les rôles outils.
 */
final class StudyAccess
{
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * Renvoie la publication si l'utilisateur courant peut l'évaluer (corpus public,
     * ou étude privée qu'il a lui-même déposée), sinon null — l'appelant traite alors
     * le cas comme « introuvable » (ne révèle pas l'existence d'une étude privée tierce).
     */
    public function accessible(?Publication $publication): ?Publication
    {
        if (null === $publication) {
            return null;
        }
        if ($publication->isListedInCorpus()) {
            return $publication;
        }
        $owner = $publication->getSubmittedBy();
        $user = $this->security->getUser();
        if (null !== $owner && $user instanceof User && $owner->getId() === $user->getId()) {
            return $publication;
        }

        return null;
    }
}
