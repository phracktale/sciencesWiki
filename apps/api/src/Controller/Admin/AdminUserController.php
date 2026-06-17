<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des utilisateurs (ROLE_ADMIN) : liste, création avec rôles
 * (invitation = mot de passe généré à transmettre, e-mail différé faute de
 * SMTP), modification des rôles.
 */
final class AdminUserController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/admin/users', name: 'admin_users_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = array_map(static fn (User $u): array => [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'name' => $u->getDisplayName(),
            'roles' => $u->getRoles(),
            'createdAt' => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->users->findBy([], ['id' => 'ASC']));

        return new JsonResponse(['items' => $items, 'availableRoles' => UserRole::values()]);
    }

    #[Route('/api/admin/users', name: 'admin_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $email = trim((string) ($data['email'] ?? ''));
        $name = trim((string) ($data['name'] ?? '')) ?: $email;
        $roles = $this->sanitizeRoles($data['roles'] ?? []);

        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'E-mail invalide.'], 422);
        }
        if (null !== $this->users->findOneByEmail($email)) {
            return new JsonResponse(['error' => 'Un compte existe déjà avec cet e-mail.'], 409);
        }

        $password = bin2hex(random_bytes(6)); // mot de passe temporaire à transmettre
        $user = new User($email, $name);
        $user->setRoles($roles ?: [UserRole::User->value])
            ->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $email,
            'roles' => $user->getRoles(),
            'temporaryPassword' => $password,
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->users->find($id);
        if (null === $user) {
            return new JsonResponse(['error' => 'Utilisateur introuvable.'], 404);
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        if (isset($data['roles'])) {
            $roles = $this->sanitizeRoles($data['roles']);
            $user->setRoles($roles ?: [UserRole::User->value]);
        }
        $this->em->flush();

        return new JsonResponse(['id' => $user->getId(), 'email' => $user->getEmail(), 'roles' => $user->getRoles()]);
    }

    /**
     * @param mixed $roles
     *
     * @return list<string>
     */
    private function sanitizeRoles(mixed $roles): array
    {
        if (!\is_array($roles)) {
            return [];
        }
        $valid = UserRole::values();

        return array_values(array_filter(array_map('strval', $roles), static fn (string $r): bool => \in_array($r, $valid, true)));
    }
}
