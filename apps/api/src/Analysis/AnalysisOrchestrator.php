<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Analysis\Claim\ClaimExtractor;
use App\Analysis\Controversy\ControversyDetector;
use App\Entity\TreeNode;
use App\Enum\AnalysisStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Point d'entrée UNIQUE de l'analyse d'un nœud (cf. docs/spec-controverses-lacunes
 * .md §7bis). Enchaîne toutes les étapes dans un seul job ; le chercheur déclenche
 * une fois et ne voit jamais les phases. CLI (`analysis:run`) et UI
 * (AnalyzeNodeMessage) en sont deux habillages.
 *
 * Verrou : un seul job par nœud (état Analyzing). À la Phase B, les étages
 * Cooccurrence → GapDetector → GapVerifier s'insèrent aux points marqués, sans
 * changer le déclenchement.
 */
final class AnalysisOrchestrator
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ClaimExtractor $extractor,
        private readonly ControversyDetector $controversyDetector,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(TreeNode $node, AnalysisOptions $options = new AnalysisOptions()): AnalysisResult
    {
        if (AnalysisStatus::Analyzing === $node->getAnalysisStatus() && !$options->force) {
            throw new \RuntimeException(\sprintf('Une analyse est déjà en cours pour le nœud « %s ».', $node->getSlug()));
        }

        $node->setAnalysisStatus(AnalysisStatus::Analyzing);
        $this->em->flush();
        $this->logger->info('Analyse démarrée', ['node' => $node->getSlug()]);

        try {
            // Phase A — extraction des assertions puis détection des controverses.
            $extraction = $this->extractor->extractForNode($node, $options->limit, $options->reextract);
            $this->logger->info('Claims extraits', $extraction);

            // --- POINT D'EXTENSION Phase B : CooccurrenceBuilder::buildForNode($node) ---

            $controversies = $this->controversyDetector->detect($node, $options->theta);
            $this->logger->info('Controverses détectées', ['count' => \count($controversies)]);

            // --- POINT D'EXTENSION Phase B : GapDetector::detect($node) → GapVerifier::verify($node, $options) ---

            $node->markAnalyzed();
            $this->em->flush();

            return new AnalysisResult(
                publications: $extraction['publications'],
                claims: $extraction['claims'],
                controversies: \count($controversies),
            );
        } catch (\Throwable $e) {
            // On relâche le verrou pour permettre une nouvelle tentative.
            $node->setAnalysisStatus(AnalysisStatus::NotAnalyzed);
            $this->em->flush();
            $this->logger->error('Analyse échouée', ['node' => $node->getSlug(), 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
