<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\TreeNode;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Gouvernance de l'article encyclopédique d'un nœud :
 *  - EDIT     : tout rédacteur identifié peut corriger le contenu ;
 *  - VALIDATE : un modérateur, ou un membre du comité compétent sur le domaine,
 *               ou un admin, peut marquer l'article « relu ».
 *
 * @extends Voter<string,TreeNode>
 */
final class ArticleVoter extends Voter
{
    public const EDIT = 'ARTICLE_EDIT';
    public const VALIDATE = 'ARTICLE_VALIDATE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::VALIDATE], true) && $subject instanceof TreeNode;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return match ($attribute) {
            self::EDIT => $this->security->isGranted('ROLE_REDACTEUR'),
            self::VALIDATE => $this->security->isGranted('ROLE_MODERATEUR')
                || ($this->security->isGranted('ROLE_COMITE') && $user->hasExpertiseOn($subject)),
            default => false,
        };
    }
}
