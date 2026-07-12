<?php

declare(strict_types=1);

namespace Analyses\Controller;

use Analyses\Analyzer\AnalysisOrchestrator;
use Analyses\Analyzer\PublicationNotFound;
use Analyses\Entity\Assessment;
use Analyses\Entity\AssessmentCriterion;
use Analyses\Entity\Evidence;
use Analyses\Repository\AssessmentCriterionRepository;
use Analyses\Repository\AssessmentRepository;
use Analyses\Repository\EvidenceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

/**
 * Analyses (SPECS §22) : lance une évaluation composite d'une publication du corpus SW
 * et relit le résultat canonique. Traitement synchrone (première version) ; passage
 * asynchrone via la capacité « queue » prévu ultérieurement.
 */
final class AnalysisController extends AbstractController
{
    public function __construct(
        private readonly AnalysisOrchestrator $orchestrator,
        private readonly AssessmentRepository $assessments,
        private readonly AssessmentCriterionRepository $criteria,
        private readonly EvidenceRepository $evidence,
    ) {
    }

    /** Corps : {"document_ref": "<id publication ou DOI>"}. */
    #[Route('/analyses', name: 'analys_analyses_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '[]', true);
        $ref = \is_array($payload) ? trim((string) ($payload['document_ref'] ?? '')) : '';
        if ('' === $ref) {
            return new JsonResponse(['error' => 'document_ref manquant.'], 400);
        }
        $designOverride = \is_array($payload) && isset($payload['study_design'])
            ? trim((string) $payload['study_design']) : null;

        try {
            $assessment = $this->orchestrator->run($ref, $designOverride ?: null);
        } catch (PublicationNotFound $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        return new JsonResponse($this->serialize($assessment), 201);
    }

    #[Route('/analyses/{id}', name: 'analys_analyses_read', methods: ['GET'])]
    public function read(string $id): JsonResponse
    {
        if (!Ulid::isValid($id)) {
            return new JsonResponse(['error' => 'Identifiant invalide.'], 400);
        }

        $assessment = $this->assessments->find(Ulid::fromString($id));
        if (null === $assessment) {
            return new JsonResponse(['error' => 'Analyse introuvable.'], 404);
        }

        return new JsonResponse($this->serialize($assessment, withDetails: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Assessment $a, bool $withDetails = false): array
    {
        $out = [
            'id' => (string) $a->getId(),
            'document_ref' => $a->getDocumentRef(),
            'status' => $a->getStatus(),
            'primary_design' => $a->getPrimaryDesign(),
            'routing_confidence' => $a->getRoutingConfidence(),
            'human_review_required' => $a->isHumanReview(),
            'model' => $a->getModel(),
            'fingerprint' => $a->getFingerprint(),
            'plan' => $a->getPlan(),
            'created_at' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($withDetails) {
            $out['criteria'] = array_map(
                static fn (AssessmentCriterion $c): array => [
                    'framework_id' => $c->getFrameworkId(),
                    'criterion_id' => $c->getCriterionId(),
                    'dimension' => $c->getDimension(),
                    'question' => $c->getQuestion(),
                    'answer' => $c->getAnswer(),
                    'evidence_type' => $c->getEvidenceType(),
                    'confidence' => $c->getConfidence(),
                    'analysis' => $c->getAnalysis(),
                    'requires_human_review' => $c->requiresHumanReview(),
                ],
                $this->criteria->findForAssessment($a->getId()),
            );
            $out['evidence'] = array_map(
                static fn (Evidence $e): array => [
                    'criterion_id' => $e->getCriterionId(),
                    'quote' => $e->getQuote(),
                    'evidence_type' => $e->getEvidenceType(),
                    'confidence' => $e->getConfidence(),
                ],
                $this->evidence->findForAssessment($a->getId()),
            );
        } else {
            // Résumé : compte des réponses par valeur.
            $summary = [];
            foreach ($this->criteria->findForAssessment($a->getId()) as $c) {
                $summary[$c->getAnswer()] = ($summary[$c->getAnswer()] ?? 0) + 1;
            }
            $out['answers_summary'] = $summary;
        }

        return $out;
    }
}
