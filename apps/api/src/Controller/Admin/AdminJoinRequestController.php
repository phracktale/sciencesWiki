<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\JoinRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demandes « Nous rejoindre » (ROLE_ADMIN) : liste, promotion (attribution d'un
 * rôle → création/maj du compte + e-mail de bienvenue), rejet. L'e-mail part via
 * le Mailer (réacheminé si le switch BO est actif ; silencieux si MAILER_DSN=null).
 */
final class AdminJoinRequestController
{
    public function __construct(
        private readonly JoinRequestRepository $requests,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\ActivityLogger $activity,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $publicUrl = 'https://scienceswiki.eu',
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $fromEmail = 'contact@scienceswiki.eu',
    ) {
    }

    private function actor(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? 'admin';
    }

    #[Route('/api/admin/join-requests', name: 'admin_join_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = trim((string) $request->query->get('status', ''));
        $criteria = '' !== $status ? ['status' => $status] : [];
        $items = array_map(static fn (\App\Entity\JoinRequest $r): array => [
            'id' => $r->getId(),
            'type' => $r->getType(),
            'firstName' => $r->getFirstName(),
            'lastName' => $r->getLastName(),
            'email' => $r->getEmail(),
            'profile' => $r->getProfile(),
            'orcid' => $r->getOrcid(),
            'profession' => $r->getProfession(),
            'message' => $r->getMessage(),
            'status' => $r->getStatus(),
            'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->requests->findBy($criteria, ['createdAt' => 'DESC'], 200));

        return new JsonResponse([
            'items' => $items,
            'availableRoles' => array_values(array_filter(UserRole::values(), static fn (string $r): bool => 'ROLE_USER' !== $r)),
        ]);
    }

    #[Route('/api/admin/join-requests/{id}/promote', name: 'admin_join_promote', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function promote(int $id, Request $request): JsonResponse
    {
        $jr = $this->requests->find($id);
        if (null === $jr) {
            return new JsonResponse(['error' => 'Demande introuvable.'], 404);
        }
        $email = (string) $jr->getEmail();
        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'La demande n\'a pas d\'e-mail valide.'], 422);
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $role = (string) ($data['role'] ?? UserRole::Redacteur->value);
        if (!\in_array($role, UserRole::values(), true)) {
            return new JsonResponse(['error' => 'Rôle invalide.'], 422);
        }

        $name = trim($jr->getFirstName().' '.$jr->getLastName());
        $tempPassword = null;
        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $tempPassword = bin2hex(random_bytes(6));
            $user = new User($email, $name ?: $email);
            $user->setRoles([$role])->setPassword($this->hasher->hashPassword($user, $tempPassword));
            $this->em->persist($user);
        } else {
            $user->setRoles(array_values(array_unique([...$user->getRoles(), $role])));
        }
        $jr->setStatus('approved');
        $this->em->flush();

        $this->sendWelcome($email, $name ?: $email, $role, $tempPassword);
        $this->activity->log('join', 'promote', $this->actor(), \sprintf('Demande #%d promue : %s → %s', $id, $email, $role), ['email' => $email, 'role' => $role]);

        return new JsonResponse(['id' => $id, 'email' => $email, 'role' => $role, 'temporaryPassword' => $tempPassword]);
    }

    #[Route('/api/admin/join-requests/{id}/reject', name: 'admin_join_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id): JsonResponse
    {
        $jr = $this->requests->find($id);
        if (null === $jr) {
            return new JsonResponse(['error' => 'Demande introuvable.'], 404);
        }
        $jr->setStatus('rejected');
        $this->em->flush();
        $this->activity->log('join', 'reject', $this->actor(), \sprintf('Demande #%d rejetée', $id), ['id' => $id]);

        return new JsonResponse(['id' => $id, 'status' => 'rejected']);
    }

    private function sendWelcome(string $email, string $name, string $role, ?string $tempPassword): void
    {
        $label = match ($role) {
            UserRole::Comite->value => 'membre du comité scientifique',
            UserRole::Moderateur->value => 'modérateur',
            UserRole::Admin->value => 'administrateur',
            default => 'rédacteur',
        };
        $body = "Bonjour $name,\n\n"
            ."Votre demande de participation à SciencesWiki a été acceptée : vous êtes désormais $label.\n\n";
        if (null !== $tempPassword) {
            $body .= "Votre compte a été créé.\n  • Identifiant : $email\n  • Mot de passe temporaire : $tempPassword\n"
                ."Merci de le changer à la première connexion : {$this->publicUrl}\n\n";
        } else {
            $body .= "Ce rôle a été ajouté à votre compte existant ($email).\n\n";
        }
        $body .= "Merci de contribuer au savoir libre.\nL'équipe SciencesWiki";

        try {
            $this->mailer->send((new Email())
                ->from($this->fromEmail)
                ->to($email)
                ->subject('Bienvenue sur SciencesWiki')
                ->text($body));
        } catch (\Throwable) {
            // Envoi best-effort : ne bloque pas la promotion si le Mailer n'est pas configuré.
        }
    }
}
