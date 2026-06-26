<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DuplicationFinding;
use App\Entity\Publication;
use App\Entity\User;
use App\Enum\FindingStatus;
use App\Repository\DuplicationFindingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office « Doublons & plagiat » (ROLE_ADMIN) : file des rapprochements à examiner
 * + décision comité (cf. docs/spec-plagiat.md §8). NON DÉCISIONNEL : rien n'est affiché
 * publiquement tant que non « Confirmed ».
 */
final class AdminDuplicationController
{
    public function __construct(
        private readonly DuplicationFindingRepository $findings,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/admin/duplications', name: 'admin_duplications', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = array_map(fn (DuplicationFinding $f): array => [
            'id' => $f->getId(),
            'type' => $f->getType()->value,
            'typeLabel' => $f->getType()->label(),
            'overlapRatio' => round($f->getOverlapRatio(), 3),
            'maxJaccard' => round($f->getMaxJaccard(), 3),
            'semanticSim' => round($f->getSemanticSim(), 3),
            'sharesAuthor' => $f->sharesAuthor(),
            'detectedAt' => $f->getDetectedAt()->format(\DateTimeInterface::ATOM),
            'source' => $this->pub($f->getSource()),
            'target' => $this->pub($f->getTarget()),
            'passages' => $f->getPassages(),
        ], $this->findings->unreviewed(100));

        return new JsonResponse(['items' => $items, 'unreviewed' => $this->findings->countUnreviewed()]);
    }

    #[Route('/api/admin/duplications/{id}/review', name: 'admin_duplication_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function review(int $id, Request $request): JsonResponse
    {
        $finding = $this->findings->find($id);
        if (null === $finding) {
            return new JsonResponse(['error' => 'Rapprochement introuvable.'], 404);
        }
        /** @var array<string,mixed> $body */
        $body = json_decode($request->getContent() ?: '[]', true) ?? [];
        $status = FindingStatus::tryFrom((string) ($body['status'] ?? ''));
        if (null === $status || FindingStatus::Unreviewed === $status) {
            return new JsonResponse(['error' => 'Statut de revue invalide.'], 422);
        }

        $user = $this->security->getUser();
        $finding->setStatus($status, $user instanceof User ? $user : null);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'status' => $status->value]);
    }

    /**
     * @return array{id:?int, title:string, year:?string, doi:?string}
     */
    private function pub(Publication $p): array
    {
        return [
            'id' => $p->getId(),
            'title' => $p->getTitle(),
            'year' => $p->getPublicationDate()?->format('Y'),
            'doi' => $p->getDoi(),
        ];
    }
}
