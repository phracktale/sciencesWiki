<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\Message\AnalyzeNodeMessage;
use App\Entity\Controversy;
use App\Enum\AnalysisStatus;
use App\Repository\ControversyRepository;
use App\Repository\PublicationRepository;
use App\Repository\TreeNodeRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    public function __construct(
        private readonly ControversyRepository $controversies,
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

        $analysable = $this->publications->countAcceptedInNode((int) $node->getId());

        return new JsonResponse([
            'node' => [
                'slug' => $node->getSlug(),
                'analysisStatus' => $node->getAnalysisStatus()->value,
                'analysable' => $analysable,
                'threshold' => self::ANALYSABLE_THRESHOLD,
                'isAnalysable' => $analysable >= self::ANALYSABLE_THRESHOLD,
                'analyzedAt' => $node->getAnalyzedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'controversies' => array_map(
                fn (Controversy $c): array => $this->serialize($c),
                $this->controversies->findByNode($node),
            ),
        ]);
    }

    #[Route('/api/tree_nodes/{slug}/analyze', name: 'api_node_analyze', methods: ['POST'])]
    public function analyze(string $slug, Request $request): JsonResponse
    {
        $node = $this->nodes->findOneBy(['slug' => $slug]);
        if (null === $node) {
            return new JsonResponse(['error' => 'Nœud introuvable.'], 404);
        }

        $status = $node->getAnalysisStatus();
        $reextract = AnalysisStatus::Stale === $status; // ré-analyse incrémentale
        if (!\in_array($status, [AnalysisStatus::NotAnalyzed, AnalysisStatus::Stale], true)) {
            return new JsonResponse([
                'analysisStatus' => $status->value,
                'queued' => false,
                'message' => AnalysisStatus::Analyzing === $status ? 'Analyse déjà en cours.' : 'Analyse déjà disponible.',
            ]);
        }

        $this->bus->dispatch(new AnalyzeNodeMessage((int) $node->getId(), reextract: $reextract));

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
