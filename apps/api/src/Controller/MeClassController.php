<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClassInvitation;
use App\Entity\SchoolClass;
use App\Entity\User;
use App\Repository\ClassInvitationRepository;
use App\Repository\SchoolClassRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace pédagogique : un enseignant (ROLE_TEACHER) crée des classes et invite ses
 * élèves par e-mail (lien à token) ; l'élève (ROLE_STUDENT) rejoint via le lien.
 * L'effectif d'une classe = les invitations acceptées. Les écritures sont sous
 * /api/me (utilisateur connecté) ; l'aperçu d'une invitation est PUBLIC (avant
 * connexion) sous /api/class/join.
 */
final class MeClassController
{
    /** Base publique pour les liens d'invitation (domaine stable). */
    private const WEB_BASE = 'https://scienceswiki.eu';

    public function __construct(
        private readonly Security $security,
        private readonly SchoolClassRepository $classes,
        private readonly ClassInvitationRepository $invitations,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $fromEmail,
    ) {
    }

    // ----------------------------- Enseignant -----------------------------

    #[Route('/api/me/classes', name: 'me_classes_list', methods: ['GET'])]
    public function listClasses(): JsonResponse
    {
        if (null === $teacher = $this->teacherOrNull()) {
            return $this->forbidden('Réservé aux enseignants.');
        }
        $out = array_map(fn (SchoolClass $c): array => $this->classPayload($c), $this->classes->findByTeacher($teacher));

        return new JsonResponse(['classes' => $out]);
    }

    #[Route('/api/me/classes', name: 'me_classes_create', methods: ['POST'])]
    public function createClass(Request $request): JsonResponse
    {
        if (null === $teacher = $this->teacherOrNull()) {
            return $this->forbidden('Réservé aux enseignants.');
        }
        $name = trim((string) ($this->body($request)['name'] ?? ''));
        if (mb_strlen($name) < 2) {
            return new JsonResponse(['error' => 'Donnez un nom de classe (au moins 2 caractères).'], 422);
        }
        $class = new SchoolClass($teacher, mb_substr($name, 0, 160));
        $this->em->persist($class);
        $this->em->flush();

        return new JsonResponse($this->classPayload($class), 201);
    }

    #[Route('/api/me/classes/{id}/invite', name: 'me_classes_invite', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function invite(int $id, Request $request): JsonResponse
    {
        if (null === $teacher = $this->teacherOrNull()) {
            return $this->forbidden('Réservé aux enseignants.');
        }
        $class = $this->classes->find($id);
        if (null === $class || $class->getTeacher()->getId() !== $teacher->getId()) {
            return new JsonResponse(['error' => 'Classe introuvable.'], 404);
        }
        $email = trim((string) ($this->body($request)['email'] ?? ''));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Adresse e-mail invalide.'], 422);
        }

        // Anti-doublon : on réémet (renvoie) l'invitation en attente existante.
        $invitation = $this->invitations->findPendingByClassAndEmail($class, $email);
        if (null === $invitation) {
            $invitation = new ClassInvitation($class, mb_substr($email, 0, 180), bin2hex(random_bytes(24)));
            $this->em->persist($invitation);
            $this->em->flush();
        }

        $link = self::WEB_BASE.'/fr/classe/rejoindre/'.$invitation->getToken();
        $sent = $this->sendInviteEmail($invitation, $teacher, $link);

        return new JsonResponse([
            'ok' => true,
            'email' => $invitation->getEmail(),
            'link' => $link,
            'emailSent' => $sent,
            'message' => $sent
                ? 'Invitation envoyée à '.$invitation->getEmail().'.'
                : 'Invitation créée, mais l’e-mail n’a pas pu être envoyé — partagez le lien manuellement.',
        ], 201);
    }

    // ------------------------------- Élève --------------------------------

    #[Route('/api/me/class/joined', name: 'me_class_joined', methods: ['GET'])]
    public function joinedClasses(): JsonResponse
    {
        $student = $this->currentUser();
        if (null === $student) {
            return $this->forbidden('Non authentifié.');
        }
        $out = [];
        foreach ($this->invitations->findAcceptedByStudent($student) as $inv) {
            $class = $inv->getSchoolClass();
            $out[] = [
                'id' => $class->getId(),
                'name' => $class->getName(),
                'teacher' => $class->getTeacher()->getDisplayName(),
                'joinedAt' => $inv->getAcceptedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse(['classes' => $out]);
    }

    #[Route('/api/me/class/join', name: 'me_class_join', methods: ['POST'])]
    public function join(Request $request): JsonResponse
    {
        $student = $this->currentUser();
        if (null === $student) {
            return $this->forbidden('Connectez-vous pour rejoindre la classe.');
        }
        $token = trim((string) ($this->body($request)['token'] ?? ''));
        $invitation = '' !== $token ? $this->invitations->findOneByToken($token) : null;
        if (null === $invitation) {
            return new JsonResponse(['error' => 'Invitation introuvable.'], 404);
        }
        if ($invitation->isAccepted()) {
            // Idempotent si c'est le même élève ; sinon l'invitation est déjà consommée.
            if ($invitation->getAcceptedBy()?->getId() === $student->getId()) {
                return new JsonResponse($this->joinPayload($invitation, 'Vous faites déjà partie de cette classe.'));
            }

            return new JsonResponse(['error' => 'Cette invitation a déjà été utilisée.'], 409);
        }
        if ($invitation->isExpired()) {
            return new JsonResponse(['error' => 'Cette invitation a expiré. Demandez-en une nouvelle à votre enseignant.'], 410);
        }

        $invitation->accept($student);
        $this->em->flush();

        return new JsonResponse($this->joinPayload($invitation, 'Vous avez rejoint la classe « '.$invitation->getSchoolClass()->getName().' ».'));
    }

    // ------------------------------ Public --------------------------------

    /** Aperçu d'une invitation (avant connexion) : nom de classe + enseignant. */
    #[Route('/api/class/join/{token}', name: 'class_join_preview', methods: ['GET'], requirements: ['token' => '[a-f0-9]{16,}'])]
    public function preview(string $token): JsonResponse
    {
        $invitation = $this->invitations->findOneByToken($token);
        if (null === $invitation) {
            return new JsonResponse(['valid' => false, 'reason' => 'not_found'], 404);
        }

        return new JsonResponse([
            'valid' => !$invitation->isExpired() && !$invitation->isAccepted(),
            'accepted' => $invitation->isAccepted(),
            'expired' => $invitation->isExpired(),
            'className' => $invitation->getSchoolClass()->getName(),
            'teacher' => $invitation->getSchoolClass()->getTeacher()->getDisplayName(),
            'email' => $invitation->getEmail(),
        ]);
    }

    // ------------------------------ Helpers -------------------------------

    private function currentUser(): ?User
    {
        $u = $this->security->getUser();

        return $u instanceof User ? $u : null;
    }

    private function teacherOrNull(): ?User
    {
        return $this->security->isGranted('ROLE_TEACHER') ? $this->currentUser() : null;
    }

    private function forbidden(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], 403);
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '[]', true);

        return \is_array($data) ? $data : [];
    }

    /** @return array<string,mixed> */
    private function classPayload(SchoolClass $class): array
    {
        $students = [];
        $pending = [];
        foreach ($this->invitations->findByClass($class) as $inv) {
            if ($inv->isAccepted() && null !== $inv->getAcceptedBy()) {
                $students[] = ['name' => $inv->getAcceptedBy()->getDisplayName(), 'email' => $inv->getAcceptedBy()->getEmail()];
            } elseif (!$inv->isExpired()) {
                $pending[] = ['email' => $inv->getEmail()];
            }
        }

        return [
            'id' => $class->getId(),
            'name' => $class->getName(),
            'createdAt' => $class->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'students' => $students,
            'pending' => $pending,
        ];
    }

    /** @return array<string,mixed> */
    private function joinPayload(ClassInvitation $invitation, string $message): array
    {
        return [
            'ok' => true,
            'className' => $invitation->getSchoolClass()->getName(),
            'teacher' => $invitation->getSchoolClass()->getTeacher()->getDisplayName(),
            'message' => $message,
        ];
    }

    private function sendInviteEmail(ClassInvitation $invitation, User $teacher, string $link): bool
    {
        $body = \sprintf(
            "Bonjour,\n\n%s vous invite à rejoindre la classe « %s » sur SciencesWiki.\n\n"
            ."Pour rejoindre la classe, ouvrez ce lien (connectez-vous ou créez un compte élève si besoin) :\n%s\n\n"
            ."Ce lien est valable 30 jours.\n\nL'équipe SciencesWiki",
            $teacher->getDisplayName(),
            $invitation->getSchoolClass()->getName(),
            $link,
        );

        try {
            $this->mailer->send((new Email())
                ->from($this->fromEmail)
                ->to($invitation->getEmail())
                ->subject('Invitation à rejoindre une classe sur SciencesWiki')
                ->text($body));

            return true;
        } catch (\Throwable) {
            return false; // best-effort : le lien est renvoyé au prof pour partage manuel
        }
    }
}
