<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\Message\AnalyzeNodeMessage;
use App\Entity\Controversy;
use App\Entity\ResearchGap;
use App\Enum\AnalysisStatus;
use App\Repository\ControversyRepository;
use App\Repository\PublicationRepository;
use App\Repository\ResearchGapRepository;
use App\Repository\TreeNodeRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exposition lecture des controverses d'un nœud et déclenchement de l'analyse
 * (cf. docs/spec-controverses-lacunes.md §8.1). Le front Twig consomme ces
 * endpoints (jamais la base directement). Les transitions comité
 * (Confirmed/Dismissed) relèvent de la Phase C.
 */
final class ControversyController
{
    /** Seuil minimal de publications validées pour qu'un nœud soit « analysable » (§0.1). */
    private const ANALYSABLE_THRESHOLD = 30;

    /** Estimation indicative du temps d'extraction par publication (modèle léger). */
    private const SECONDS_PER_PUBLICATION = 25;

    public function __construct(
        private readonly ControversyRepository $controversies,
        private readonly ResearchGapRepository $gaps,
        private readonly TreeNodeRepository $nodes,
        private readonly PublicationRepository $publications,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/tree_nodes/{slug}/controversies', name: 'api_node_controversies', methods: ['GET'])]
    public function list(string $slug): JsonResponse
    {
        $node = $this->nodes->findOneBy(['slug' => $slug]);
        if (null === $node) {
            return new JsonResponse(['error' => 'Nœud introuvable.'], 404);
        }

        $analysable = $this->publications->countPlacedInNode((int) $node->getId());

        return new JsonResponse([
            'node' => [
                'slug' => $node->getSlug(),
                'analysisStatus' => $node->getAnalysisStatus()->value,
                'analysable' => $analysable,
                'threshold' => self::ANALYSABLE_THRESHOLD,
                'isAnalysable' => $analysable >= self::ANALYSABLE_THRESHOLD,
                'analyzedAt' => $node->getAnalyzedAt()?->format(\DateTimeInterface::ATOM),
                'analysisStartedAt' => $node->getAnalysisStartedAt()?->format(\DateTimeInterface::ATOM),
                // Estimation indicative pour l'ETA côté UI (s/publi, modèle léger).
                'secondsPerPublication' => self::SECONDS_PER_PUBLICATION,
            ],
            'controversies' => array_map(
                fn (Controversy $c): array => $this->serialize($c),
                $this->controversies->findByNode($node),
            ),
            'gaps' => array_map(
                fn (ResearchGap $g): array => $this->serializeGap($g),
                $this->gaps->findByNode($node),
            ),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeGap(ResearchGap $g): array
    {
        return [
            'id' => $g->getId(),
            'type' => $g->getType()->value,
            'conceptA' => $g->getConceptA(),
            'conceptB' => $g->getConceptB(),
            'conceptC' => $g->getConceptC(),
            'description' => $g->getDescription(),
            'maturityScore' => $g->getMaturityScore(),
            'rarityScore' => $g->getRarityScore(),
            'evidenceCount' => $g->getEvidenceCount(),
            'verification' => $g->getVerification()->value,
            'status' => $g->getStatus()->value,
        ];
    }

    #[Route('/api/tree_nodes/{slug}/analyze', name: 'api_node_analyze', methods: ['POST'])]
    public function analyze(string $slug): JsonResponse
    {
        $node = $this->nodes->findOneBy(['slug' => $slug]);
        if (null === $node) {
            return new JsonResponse(['error' => 'Nœud introuvable.'], 404);
        }

        // Seul un job déjà en cours bloque ; sinon on (re)lance — y compris depuis
        // « ready » : le corpus évolue, le chercheur doit pouvoir rafraîchir.
        if (AnalysisStatus::Analyzing === $node->getAnalysisStatus()) {
            return new JsonResponse([
                'analysisStatus' => AnalysisStatus::Analyzing->value,
                'queued' => false,
                'message' => 'Analyse déjà en cours.',
            ]);
        }

        // Incrémental : seules les publications sans claims sont ré-extraites
        // (les nouvelles), puis controverses et pistes sont recalculées.
        $this->bus->dispatch(new AnalyzeNodeMessage((int) $node->getId(), reextract: false));

        return new JsonResponse(['analysisStatus' => AnalysisStatus::Analyzing->value, 'queued' => true]);
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(Controversy $controversy): array
    {
        // Claims ordonnés par id : l'ordre fonde les marqueurs [n] de la synthèse.
        $claims = $controversy->getClaims()->toArray();
        usort($claims, static fn ($a, $b): int => (int) $a->getId() <=> (int) $b->getId());

        $rows = [];
        foreach ($claims as $i => $claim) {
            $publication = $claim->getPublication();
            $date = $publication->getPublicationDate();
            $rows[] = [
                'marker' => $i + 1,
                'direction' => $claim->getDirection()->value,
                'method' => $claim->getMethod()->value,
                'confidence' => $claim->getConfidence()->value,
                'population' => $claim->getPopulation(),
                'effectSize' => $claim->getEffectSize(),
                'quote' => $claim->getQuote(),
                'doi' => $publication->getDoi(),
                'title' => $publication->getTitle(),
                'year' => $date?->format('Y'),
                'oaUrl' => $publication->getOaUrl(),
            ];
        }

        return [
            'id' => $controversy->getId(),
            'exposure' => $controversy->getExposureNorm(),
            'outcome' => $controversy->getOutcomeNorm(),
            'consensusScore' => $controversy->getConsensusScore(),
            'countPositive' => $controversy->getCountPositive(),
            'countNegative' => $controversy->getCountNegative(),
            'countNull' => $controversy->getCountNull(),
            'disagreementAxis' => $controversy->getDisagreementAxis()->value,
            'summary' => $controversy->getSummary(),
            'status' => $controversy->getStatus()->value,
            'claims' => $rows,
        ];
    }
}
