<?php

declare(strict_types=1);

namespace Analyses\Controller;

use Analyses\Repository\AssessmentCriterionRepository;
use Analyses\Repository\AssessmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

/**
 * Validation humaine (human-in-the-loop, SPECS §2.1, §25) : un relecteur (ROLE_COMITE)
 * corrige une réponse de critère et/ou valide l'évaluation. La correction est tracée
 * (auteur + date) et prime sur la réponse IA, sans écraser cette dernière.
 */
final class ReviewController extends AbstractController
{
    public function __construct(
        private readonly AssessmentRepository $assessments,
        private readonly AssessmentCriterionRepository $criteria,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Corrige la réponse d'un critère. Corps : {"answer": "...", "analysis": "..."}. */
    #[Route('/analyses/{id}/criteria/{criterionId}', name: 'analys_review_criterion', methods: ['PATCH'])]
    public function reviewCriterion(string $id, string $criterionId, Request $request): JsonResponse
    {
        if (!Ulid::isValid($id)) {
            return new JsonResponse(['error' => 'Identifiant invalide.'], 400);
        }
        $assessment = $this->assessments->find(Ulid::fromString($id));
        if (null === $assessment) {
            return new JsonResponse(['error' => 'Analyse introuvable.'], 404);
        }

        $payload = json_decode($request->getContent() ?: '[]', true);
        $answer = \is_array($payload) ? trim((string) ($payload['answer'] ?? '')) : '';
        if ('' === $answer) {
            return new JsonResponse(['error' => 'answer manquant.'], 400);
        }

        $criterion = $this->criteria->findOneBy([
            'assessmentId' => $assessment->getId(),
            'criterionId' => $criterionId,
        ]);
        if (null === $criterion) {
            return new JsonResponse(['error' => 'Critère introuvable.'], 404);
        }

        $reviewer = $this->getUser()?->getUserIdentifier() ?? 'inconnu';
        $criterion->applyHumanReview($answer, \is_array($payload) ? ($payload['analysis'] ?? null) : null, $reviewer);
        $this->em->flush();

        return new JsonResponse([
            'criterion_id' => $criterion->getCriterionId(),
            'answer' => $criterion->effectiveAnswer(),
            'ai_answer' => $criterion->getAnswer(),
            'human_answer' => $criterion->getHumanAnswer(),
            'reviewed_by' => $criterion->getReviewedBy(),
            'reviewed_at' => $criterion->getReviewedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** Valide l'évaluation (statut « validated »). */
    #[Route('/analyses/{id}/validate', name: 'analys_review_validate', methods: ['POST'])]
    public function validate(string $id): JsonResponse
    {
        if (!Ulid::isValid($id)) {
            return new JsonResponse(['error' => 'Identifiant invalide.'], 400);
        }
        $assessment = $this->assessments->find(Ulid::fromString($id));
        if (null === $assessment) {
            return new JsonResponse(['error' => 'Analyse introuvable.'], 404);
        }

        $assessment->validate($this->getUser()?->getUserIdentifier() ?? 'inconnu');
        $this->em->flush();

        return new JsonResponse([
            'id' => (string) $assessment->getId(),
            'status' => $assessment->getStatus(),
            'validated_by' => $assessment->getValidatedBy(),
            'validated_at' => $assessment->getValidatedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
