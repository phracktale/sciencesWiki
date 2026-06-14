<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Profil de l'utilisateur authentifié (JWT requis — cf. security.yaml).
 */
final class MeController
{
    public function __construct(private readonly Security $security)
    {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }

        return new JsonResponse([
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'roles' => $user->getRoles(),
            'profileType' => $user->getProfileType()->value,
            'identityVerified' => $user->isIdentityVerified(),
            'orcid' => $user->getOrcid(),
            'expertise' => array_map(
                static fn ($e): string => $e->getTreeNode()->getSlug(),
                $user->getExpertise()->toArray(),
            ),
        ]);
    }
}
