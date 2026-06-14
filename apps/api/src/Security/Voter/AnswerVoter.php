<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Answer;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Règle de gouvernance (cf. spec §4/§8.4) : seul un membre du **comité**
 * compétent sur le domaine du nœud (ou un admin) peut valider une réponse ;
 * un **rédacteur** peut l'éditer.
 *
 * @extends Voter<string,Answer>
 */
final class AnswerVoter extends Voter
{
    public const VALIDATE = 'ANSWER_VALIDATE';
    public const EDIT = 'ANSWER_EDIT';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VALIDATE, self::EDIT], true) && $subject instanceof Answer;
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
            // Validation : comité + compétence sur le domaine du nœud.
            self::VALIDATE => $this->security->isGranted('ROLE_COMITE')
                && $user->hasExpertiseOn($subject->getTreeNode()),
            // Édition : tout rédacteur identifié.
            self::EDIT => $this->security->isGranted('ROLE_REDACTEUR'),
            default => false,
        };
    }
}
