<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Analysis\Axis\AxisSerializer;
use App\Entity\AxisAppraisal;
use App\Entity\Publication;
use App\Entity\User;
use App\Enum\ReviewStatus;
use App\Repository\AxisAppraisalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office « Évaluations AXIS » (ROLE_ADMIN) : file des évaluations à examiner
 * (prévisualisation des Detected/UnderReview) + décision comité. NON DÉCISIONNEL :
 * rien n'est affiché publiquement tant que non « Confirmed » (cf. docs/spec-axis
 * -articles.md §3).
 */
final class AdminAxisController
{
    public function __construct(
        private readonly AxisAppraisalRepository $appraisals,
        private readonly AxisSerializer $serializer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/admin/axis', name: 'admin_axis_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = array_map(function (AxisAppraisal $a): array {
            $data = $this->serializer->serialize($a);
            $data['publication'] = $this->pub($a->getPublication());

            return $data;
        }, $this->appraisals->pending(100));

        return new JsonResponse(['items' => $items, 'pending' => $this->appraisals->countPending()]);
    }

    #[Route('/api/admin/axis/{id}/review', name: 'admin_axis_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function review(int $id, Request $request): JsonResponse
    {
        $appraisal = $this->appraisals->find($id);
        if (null === $appraisal) {
            return new JsonResponse(['error' => 'Évaluation introuvable.'], 404);
        }
        /** @var array<string,mixed> $body */
        $body = json_decode($request->getContent() ?: '[]', true) ?? [];
        $status = ReviewStatus::tryFrom((string) ($body['status'] ?? ''));
        if (null === $status || ReviewStatus::Detected === $status) {
            return new JsonResponse(['error' => 'Statut de revue invalide.'], 422);
        }

        $user = $this->security->getUser();
        $appraisal->setStatus($status, $user instanceof User ? $user : null);
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
