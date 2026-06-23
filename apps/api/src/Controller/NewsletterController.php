<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\NewsletterSignup;
use App\Repository\NewsletterSignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inscription « tenez-moi au courant » par cible (journalistes, grand public…).
 * Stockage léger pour gestion en back-office. Anti-spam : pot de miel + e-mail
 * valide. Idempotent (un e-mail déjà inscrit renvoie un succès).
 */
final class NewsletterController
{
    /** @var list<string> */
    private const AUDIENCES = ['journalists', 'public', 'researchers', 'teachers', 'other'];

    public function __construct(
        private readonly NewsletterSignupRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\ActivityLogger $activity,
    ) {
    }

    #[Route('/api/newsletter', name: 'api_newsletter_signup', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $d */
        $d = json_decode($request->getContent() ?: '[]', true) ?? [];

        if ('' !== trim((string) ($d['website'] ?? ''))) {
            return new JsonResponse(['ok' => true], Response::HTTP_CREATED); // pot de miel
        }

        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Adresse e-mail invalide.'], 422);
        }
        $audience = (string) ($d['audience'] ?? 'other');
        if (!\in_array($audience, self::AUDIENCES, true)) {
            $audience = 'other';
        }

        if (!$this->repository->existsForEmail($email)) {
            $signup = (new NewsletterSignup($email, $audience))
                ->setName(($v = trim((string) ($d['name'] ?? ''))) !== '' ? $v : null);
            $this->em->persist($signup);
            $this->em->flush();
            $this->activity->log('newsletter', 'signup', $email, \sprintf('Inscription newsletter (%s).', $audience), ['audience' => $audience], $request->getClientIp() ?? '0.0.0.0');
        }

        return new JsonResponse(['ok' => true, 'message' => 'Inscription enregistrée. Merci !'], Response::HTTP_CREATED);
    }
}
