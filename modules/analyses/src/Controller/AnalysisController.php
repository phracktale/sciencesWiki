<?php

declare(strict_types=1);

namespace Analyses\Controller;

use Analyses\Analyzer\AnalysisOrchestrator;
use Analyses\Analyzer\PublicationNotFound;
use Analyses\Entity\Assessment;
use Analyses\Entity\AssessmentCriterion;
use Analyses\Entity\Evidence;
use Analyses\Pdf\PdfRenderer;
use Analyses\Projection\ProjectionEngine;
use Analyses\Repository\AssessmentCriterionRepository;
use Analyses\Repository\AssessmentRepository;
use Analyses\Repository\EvidenceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly ProjectionEngine $projection,
        private readonly PdfRenderer $pdf,
    ) {
    }

    /**
     * Met une analyse en file (asynchrone) et retourne 202 avec son identifiant.
     * Corps : {"document_ref": "<id publication ou DOI>", "study_design": "<override optionnel>"}.
     * Suivre l'avancement via GET /analyses/{id} (statut queued → running → completed).
     */
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

        // Demandeur = identité du JWT (l'e-mail SciencesWiki), pour la notification.
        $requestedBy = $this->getUser()?->getUserIdentifier();

        try {
            $assessment = $this->orchestrator->queue($ref, $designOverride ?: null, $requestedBy);
        } catch (PublicationNotFound $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (\Analyses\Analyzer\AbstractOnlyException $e) {
            $pub = $e->publication();
            $doi = \is_string($pub['doi'] ?? null) ? (string) $pub['doi'] : null;
            return new JsonResponse([
                'status' => 'abstract_only',
                'error' => "Seul le résumé de cette étude est disponible. Une évaluation fiable exige le texte intégral : "
                    ."récupérez le PDF via son DOI".($doi ? " (https://doi.org/$doi)" : '')." et déposez-le dans « Analyses » (/fr/analyses).",
                'publication' => $pub,
            ], 422);
        }

        return new JsonResponse([
            'id' => (string) $assessment->getId(),
            'status' => $assessment->getStatus(),
            'document_ref' => $assessment->getDocumentRef(),
            'poll' => '/analyses/'.$assessment->getId(),
            'message' => "Analyse mise en file. Le traitement n'est pas instantané ; suivez le statut via le lien poll.",
        ], 202);
    }

    /**
     * Classeur personnel : liste horodatée des analyses demandées par l'utilisateur courant
     * (titre, DOI, chemin arborescent, statut). Toujours filtré sur l'identité du JWT.
     */
    #[Route('/me/analyses', name: 'analys_me_analyses', methods: ['GET'])]
    public function myAnalyses(): JsonResponse
    {
        $me = $this->getUser()?->getUserIdentifier();
        if (null === $me) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }

        $items = array_map(
            fn (Assessment $a): array => [
                'id' => (string) $a->getId(),
                'document_ref' => $a->getDocumentRef(),
                'title' => $a->getDocumentTitle(),
                'doi' => $a->getDocumentDoi(),
                'tree_path' => $a->getTreePath(),
                'tree_path_label' => $a->treePathLabel(),
                'status' => $a->getStatus(),
                'primary_design' => $a->getPrimaryDesign(),
                'human_review_required' => $a->isHumanReview(),
                'created_at' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->assessments->findForUser($me),
        );

        return new JsonResponse(['items' => $items]);
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

    /** Export PDF de l'évaluation (généré à la volée, port pdf:render). */
    #[Route('/analyses/{id}/pdf', name: 'analys_analyses_pdf', methods: ['GET'])]
    public function pdf(string $id): Response
    {
        if (!Ulid::isValid($id)) {
            return new JsonResponse(['error' => 'Identifiant invalide.'], 400);
        }
        $assessment = $this->assessments->find(Ulid::fromString($id));
        if (null === $assessment) {
            return new JsonResponse(['error' => 'Analyse introuvable.'], 404);
        }

        $binary = $this->pdf->render($assessment, $this->criteria->findForAssessment($assessment->getId()));

        return new Response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('inline; filename="analyse-%s.pdf"', $assessment->getId()),
        ]);
    }

    /** Projection du résultat canonique selon un rôle (SPECS §20). */
    #[Route('/analyses/{id}/projection/{role}', name: 'analys_analyses_projection', methods: ['GET'])]
    public function projection(string $id, string $role): JsonResponse
    {
        if (!Ulid::isValid($id)) {
            return new JsonResponse(['error' => 'Identifiant invalide.'], 400);
        }
        $assessment = $this->assessments->find(Ulid::fromString($id));
        if (null === $assessment) {
            return new JsonResponse(['error' => 'Analyse introuvable.'], 404);
        }

        return new JsonResponse($this->projection->project(
            $assessment,
            $this->criteria->findForAssessment($assessment->getId()),
            $this->evidence->findForAssessment($assessment->getId()),
            $role,
        ));
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
            'validated_by' => $a->getValidatedBy(),
            'validated_at' => $a->getValidatedAt()?->format(\DateTimeInterface::ATOM),
            'applicable' => $a->isApplicable(),
            'summary' => $a->getSummary(),
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
                    'answer' => $c->effectiveAnswer(),
                    'ai_answer' => $c->getAnswer(),
                    'human_answer' => $c->getHumanAnswer(),
                    'reviewed_by' => $c->getReviewedBy(),
                    'verdict' => $c->getVerdict(),
                    'expected' => $c->getExpected(),
                    'evidence_found' => $c->getEvidenceFound(),
                    'analysis' => $c->getAnalysis(),
                    'limitations' => $c->getLimitations(),
                    'evidence_type' => $c->getEvidenceType(),
                    'overall_evidence_type' => $c->getOverallEvidenceType(),
                    'confidence' => $c->getConfidence(),
                    'requires_visual_check' => $c->requiresVisualCheck(),
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
