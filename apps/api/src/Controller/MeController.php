<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Profil de l'utilisateur authentifié (JWT requis — cf. security.yaml) : lecture (GET) et
 * édition en libre-service des champs d'identité affichables (PATCH).
 */
final class MeController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }

        return new JsonResponse($this->profile($user));
    }

    /**
     * Édition en libre-service du profil : nom réel, pseudo, ORCID, affiliation, bio.
     * Les autres champs (email, rôles, statut, vérification) NE sont PAS modifiables ici.
     */
    #[Route('/api/me', name: 'api_me_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }

        $data = json_decode($request->getContent() ?: '[]', true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Corps de requête invalide.'], 400);
        }

        // Nom réel : requis, non vidable (sert d'identité par défaut).
        if (\array_key_exists('realName', $data)) {
            $realName = trim((string) $data['realName']);
            if ('' === $realName) {
                return new JsonResponse(['error' => 'Le nom ne peut pas être vide.'], 422);
            }
            $user->setRealName(mb_substr($realName, 0, 255));
        }
        // Champs optionnels : une chaîne vide efface la valeur (null).
        if (\array_key_exists('pseudo', $data)) {
            $user->setPseudo($this->optional($data['pseudo'], 120));
        }
        if (\array_key_exists('affiliation', $data)) {
            $user->setAffiliation($this->optional($data['affiliation'], 255));
        }
        if (\array_key_exists('orcid', $data)) {
            $user->setOrcid($this->optional($data['orcid'], 32));
        }
        if (\array_key_exists('bio', $data)) {
            $bio = trim((string) $data['bio']);
            $user->setBio('' === $bio ? null : $bio);
        }

        $this->em->flush();

        return new JsonResponse($this->profile($user));
    }

    /** Normalise un champ texte optionnel : trim, null si vide, tronqué à la taille de colonne. */
    private function optional(mixed $value, int $max): ?string
    {
        $s = trim((string) $value);

        return '' === $s ? null : mb_substr($s, 0, $max);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'realName' => $user->getRealName(),
            'pseudo' => $user->getPseudo(),
            'affiliation' => $user->getAffiliation(),
            'orcid' => $user->getOrcid(),
            'bio' => $user->getBio(),
            'roles' => $user->getRoles(),
            'profileType' => $user->getProfileType()->value,
            'identityVerified' => $user->isIdentityVerified(),
            'expertise' => array_map(
                static fn ($e): string => $e->getTreeNode()->getSlug(),
                $user->getExpertise()->toArray(),
            ),
        ];
    }
}
