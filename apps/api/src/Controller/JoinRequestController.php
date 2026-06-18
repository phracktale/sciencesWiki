<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\JoinRequest;
use App\Repository\JoinRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réception des demandes « Nous rejoindre » (comité scientifique, auteur,
 * rédacteur) depuis le front public. Stockées pour traitement en back-office.
 */
final class JoinRequestController
{
    private const TYPES = ['committee', 'author', 'editor'];
    private const RATE_LIMIT_PER_HOUR = 5;

    public function __construct(
        private readonly JoinRequestRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\ActivityLogger $activity,
    ) {
    }

    #[Route('/api/join-requests', name: 'api_join_request', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $d */
        $d = json_decode($request->getContent() ?: '[]', true) ?? [];

        $type = (string) ($d['type'] ?? '');
        $lastName = trim((string) ($d['lastName'] ?? ''));
        $firstName = trim((string) ($d['firstName'] ?? ''));
        if (!\in_array($type, self::TYPES, true)) {
            return new JsonResponse(['error' => 'Type de demande invalide.'], 422);
        }
        if ('' === $lastName || '' === $firstName) {
            return new JsonResponse(['error' => 'Nom et prénom sont obligatoires.'], 422);
        }

        $ip = $request->getClientIp() ?? '0.0.0.0';
        $recent = (int) $this->em->getConnection()->executeQuery(
            "SELECT count(*) FROM join_request WHERE ip = :ip AND created_at > now() - interval '1 hour'",
            ['ip' => $ip],
        )->fetchOne();
        if ($recent >= self::RATE_LIMIT_PER_HOUR) {
            return new JsonResponse(['error' => 'Trop de demandes récentes. Merci de réessayer plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $jr = new JoinRequest($type, mb_substr($lastName, 0, 120), mb_substr($firstName, 0, 120));
        $jr->setEmail(($v = trim((string) ($d['email'] ?? ''))) !== '' ? mb_substr($v, 0, 180) : null)
            ->setProfile(\in_array($d['profile'] ?? '', ['chercheur', 'vulgarisateur'], true) ? (string) $d['profile'] : null)
            ->setOrcid(($v = trim((string) ($d['orcid'] ?? ''))) !== '' ? mb_substr($v, 0, 32) : null)
            ->setProfession(($v = trim((string) ($d['profession'] ?? ''))) !== '' ? mb_substr($v, 0, 180) : null)
            ->setMessage(($v = trim((string) ($d['message'] ?? ''))) !== '' ? $v : null)
            ->setIp($ip);

        $this->em->persist($jr);
        $this->em->flush();

        $this->activity->log('join', 'request', $firstName.' '.$lastName, \sprintf('Demande « %s » reçue.', $type), ['type' => $type, 'email' => $jr->getEmail()], $ip);

        return new JsonResponse(['ok' => true, 'message' => 'Merci ! Votre demande a bien été reçue ; nous reviendrons vers vous.'], Response::HTTP_CREATED);
    }
}
