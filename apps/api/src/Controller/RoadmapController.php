<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RoadmapProposal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réception des propositions de roadmap (page publique /roadmap) : stockage en
 * base (gestion back-office) ET notification e-mail à l'équipe. Anti-spam : pot
 * de miel (« website ») ; l'e-mail de l'auteur est facultatif.
 */
final class RoadmapController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\ActivityLogger $activity,
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'HARVESTER_CONTACT_EMAIL')]
        private readonly string $contactEmail = 'contact@scienceswiki.eu',
    ) {
    }

    #[Route('/api/roadmap/proposals', name: 'api_roadmap_propose', methods: ['POST'])]
    public function propose(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $d */
        $d = json_decode($request->getContent() ?: '[]', true) ?? [];

        // Pot de miel : rempli par un bot → succès simulé, rien n'est fait.
        if ('' !== trim((string) ($d['website'] ?? ''))) {
            return new JsonResponse(['ok' => true], Response::HTTP_CREATED);
        }

        $message = trim((string) ($d['message'] ?? ''));
        if (mb_strlen($message) < 8) {
            return new JsonResponse(['error' => 'Décrivez votre proposition (8 caractères minimum).'], 422);
        }
        $name = trim((string) ($d['name'] ?? ''));
        $email = trim((string) ($d['email'] ?? ''));
        if ('' !== $email && !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Adresse e-mail invalide.'], 422);
        }

        $proposal = new RoadmapProposal(mb_substr($message, 0, 5000));
        $proposal->setName('' !== $name ? $name : null)->setEmail('' !== $email ? $email : null);
        $this->em->persist($proposal);
        $this->em->flush();

        $ip = $request->getClientIp() ?? '0.0.0.0';
        try {
            $this->mailer->send((new Email())
                ->from($this->contactEmail)
                ->to($this->contactEmail)
                ->subject('[Roadmap] Nouvelle proposition'.('' !== $name ? ' — '.$name : ''))
                ->text("Nouvelle proposition de roadmap SciencesWiki\n\n"
                    .'Nom ..... '.('' !== $name ? $name : '(non renseigné)')."\n"
                    .'E-mail .. '.('' !== $email ? $email : '(non renseigné)')."\n"
                    ."IP ...... $ip\n\n"
                    ."Proposition :\n$message\n"));
        } catch (\Throwable) {
            // Best-effort : la proposition est déjà persistée en base.
        }

        $this->activity->log('roadmap', 'proposal', '' !== $name ? $name : ($email ?: 'anonyme'), 'Proposition de roadmap reçue.', [
            'id' => $proposal->getId(),
            'email' => $email,
            'message' => mb_substr($message, 0, 500),
        ], $ip);

        return new JsonResponse(['ok' => true], Response::HTTP_CREATED);
    }
}
