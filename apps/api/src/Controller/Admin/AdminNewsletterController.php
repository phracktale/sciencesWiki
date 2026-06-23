<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\NewsletterSignup;
use App\Repository\NewsletterSignupRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liste back-office des inscriptions newsletter par cible (ROLE_ADMIN).
 */
final class AdminNewsletterController
{
    public function __construct(
        private readonly NewsletterSignupRepository $signups,
    ) {
    }

    #[Route('/api/admin/newsletter-signups', name: 'admin_newsletter_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $audience = trim((string) $request->query->get('audience', ''));
        $criteria = '' !== $audience ? ['audience' => $audience] : [];

        $items = array_map(static fn (NewsletterSignup $s): array => [
            'id' => $s->getId(),
            'email' => $s->getEmail(),
            'name' => $s->getName(),
            'audience' => $s->getAudience(),
            'createdAt' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->signups->findBy($criteria, ['createdAt' => 'DESC'], 1000));

        return new JsonResponse(['items' => $items, 'total' => \count($items)]);
    }
}
