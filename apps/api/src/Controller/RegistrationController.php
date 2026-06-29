<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inscription self-service pour les espaces pédagogiques / recherche
 * (chercheur, enseignant, élève). Le compte est créé NON vérifié : ces rôles
 * donnent accès aux OUTILS (déclencher une évaluation AXIS, espace perso, classe)
 * mais PAS à l'édition du contenu public (réservée au comité / identités vérifiées).
 * Renvoie un JWT pour connecter l'utilisateur immédiatement. Endpoint PUBLIC.
 */
final class RegistrationController
{
    /** Rôles ouverts à l'auto-inscription (forme courte → rôle Symfony). */
    private const SELF_ROLES = [
        'researcher' => UserRole::Researcher,
        'teacher' => UserRole::Teacher,
        'student' => UserRole::Student,
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly JWTTokenManagerInterface $jwt,
    ) {
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent() ?: '[]', true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Requête invalide.'], 400);
        }

        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $realName = trim((string) ($data['realName'] ?? $data['real_name'] ?? ''));
        $roleKey = mb_strtolower(trim((string) ($data['role'] ?? '')));
        // Tolère la forme longue « ROLE_TEACHER » → « teacher ».
        $roleKey = str_replace('role_', '', $roleKey);

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Adresse e-mail invalide.'], 422);
        }
        if (mb_strlen($password) < 8) {
            return new JsonResponse(['error' => 'Mot de passe trop court (8 caractères minimum).'], 422);
        }
        if ('' === $realName) {
            return new JsonResponse(['error' => 'Le nom est obligatoire.'], 422);
        }
        if (!isset(self::SELF_ROLES[$roleKey])) {
            return new JsonResponse(['error' => 'Rôle invalide (chercheur, enseignant ou élève).'], 422);
        }
        if (null !== $this->users->findOneByEmail($email)) {
            return new JsonResponse(['error' => 'Un compte existe déjà avec cette adresse.'], 409);
        }

        $user = new User($email, $realName);
        $user
            ->setRoles([self::SELF_ROLES[$roleKey]->value])
            ->setPassword($this->hasher->hashPassword($user, $password));

        if (!empty($data['pseudo'])) {
            $user->setPseudo(trim((string) $data['pseudo']));
        }
        if (!empty($data['affiliation'])) {
            $user->setAffiliation(trim((string) $data['affiliation']));
        }
        if (!empty($data['orcid'])) {
            $user->setOrcid(trim((string) $data['orcid']));
        }

        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'token' => $this->jwt->create($user),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'roles' => $user->getRoles(),
        ], 201);
    }
}
