<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RoadmapProposal;
use App\Repository\RoadmapProposalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion back-office des propositions de roadmap (ROLE_ADMIN) : liste et
 * changement de statut (new → planned / declined / done).
 */
final class AdminRoadmapController
{
    /** @var list<string> */
    private const STATUSES = ['new', 'planned', 'declined', 'done'];

    public function __construct(
        private readonly RoadmapProposalRepository $proposals,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\ActivityLogger $activity,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
    ) {
    }

    #[Route('/api/admin/roadmap-proposals', name: 'admin_roadmap_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = trim((string) $request->query->get('status', ''));
        $criteria = \in_array($status, self::STATUSES, true) ? ['status' => $status] : [];

        $items = array_map(static fn (RoadmapProposal $p): array => [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'email' => $p->getEmail(),
            'message' => $p->getMessage(),
            'status' => $p->getStatus(),
            'createdAt' => $p->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->proposals->findBy($criteria, ['createdAt' => 'DESC'], 300));

        return new JsonResponse(['items' => $items, 'statuses' => self::STATUSES]);
    }

    #[Route('/api/admin/roadmap-proposals/{id}/status', name: 'admin_roadmap_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setStatus(int $id, Request $request): JsonResponse
    {
        $proposal = $this->proposals->find($id);
        if (null === $proposal) {
            return new JsonResponse(['error' => 'Proposition introuvable.'], 404);
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $status = (string) ($data['status'] ?? '');
        if (!\in_array($status, self::STATUSES, true)) {
            return new JsonResponse(['error' => 'Statut invalide.'], 422);
        }

        $proposal->setStatus($status);
        $this->em->flush();
        $this->activity->log('roadmap', 'status', $this->security->getUser()?->getUserIdentifier() ?? 'admin', \sprintf('Proposition #%d → %s', $id, $status), ['id' => $id, 'status' => $status]);

        return new JsonResponse(['id' => $id, 'status' => $status]);
    }
}
