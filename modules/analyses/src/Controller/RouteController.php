<?php

declare(strict_types=1);

namespace Analyses\Controller;

use Analyses\Framework\FrameworkRegistry;
use Analyses\Ontology\StudyDesign;
use Analyses\Router\RouterEngine;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routage composite (SPECS §22). Déterministe : construit un plan d'analyse à partir
 * d'une empreinte (type d'étude + finalités/domaines/modalités) sans appel LLM.
 */
final class RouteController extends AbstractController
{
    public function __construct(
        private readonly RouterEngine $router,
        private readonly FrameworkRegistry $frameworks,
    ) {
    }

    /** Liste les référentiels enregistrés (plugins). */
    #[Route('/frameworks', name: 'analys_frameworks', methods: ['GET'])]
    public function frameworks(): JsonResponse
    {
        return new JsonResponse(['frameworks' => $this->frameworks->metadataList()]);
    }

    /**
     * Construit un plan d'analyse composite.
     * Corps : {"study_design": "cross_sectional", "objectives": [...], "domains": [...], "modalities": [...]}.
     */
    #[Route('/routes', name: 'analys_routes', methods: ['POST'])]
    public function routes(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '[]', true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'Corps JSON invalide.'], 400);
        }

        $design = StudyDesign::tryFrom((string) ($payload['study_design'] ?? ''));
        if (null === $design) {
            return new JsonResponse([
                'error' => 'study_design inconnu ou manquant.',
                'accepted' => array_map(static fn (StudyDesign $d): string => $d->value, StudyDesign::cases()),
            ], 422);
        }

        $plan = $this->router->buildPlan(
            $design,
            array_values(array_filter((array) ($payload['objectives'] ?? []), 'is_string')),
            array_values(array_filter((array) ($payload['domains'] ?? []), 'is_string')),
            array_values(array_filter((array) ($payload['modalities'] ?? []), 'is_string')),
        );

        // Enrichit le plan avec les métadonnées des référentiels connus du registre.
        $plan['primary_frameworks_meta'] = array_values(array_filter(array_map(
            fn (string $id): ?array => ($f = $this->frameworks->get($id)) ? ['id' => $id, ...$f->metadata()] : null,
            $plan['primary_frameworks'],
        )));
        $plan['design_label'] = $design->label();

        return new JsonResponse($plan);
    }
}
