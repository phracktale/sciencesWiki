<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Analysis\Axis\AxisAppraiser;
use App\Analysis\Claim\ClaimExtractor;
use App\Analysis\Controversy\ControversyDetector;
use App\Analysis\Gap\GapDetector;
use App\Entity\TreeNode;
use App\Enum\AnalysisStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
        private readonly GapDetector $gapDetector,
        private readonly AxisAppraiser $axisAppraiser,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(TreeNode $node, AnalysisOptions $options = new AnalysisOptions()): AnalysisResult
    {
        if (AnalysisStatus::Analyzing === $node->getAnalysisStatus() && !$options->force) {
            throw new \RuntimeException(\sprintf('Une analyse est déjà en cours pour le nœud « %s ».', $node->getSlug()));
        }

        $nodeId = (int) $node->getId();
        $node->setAnalysisStatus(AnalysisStatus::Analyzing);
        $node->markAnalysisStarted();
        $this->em->flush();
        $this->logger->info('Analyse démarrée', ['node' => $node->getSlug()]);

        try {
            // Phase A — extraction des assertions puis détection des controverses.
            $extraction = $this->extractor->extractForNode($node, $options->limit, $options->reextract);
            $this->logger->info('Claims extraits', $extraction);

            // Évaluation critique AXIS des études transversales du nœud (par article,
            // verrou d'applicabilité, cf. docs/spec-axis-articles.md). Idempotente comme
            // l'extraction : incrémentale par défaut, complète si --reextract/force.
            $axis = $this->axisAppraiser->appraiseForNode($node, $options->limit, $options->reextract);
            $this->logger->info('Évaluations AXIS', $axis);

            // --- POINT D'EXTENSION Phase B : CooccurrenceBuilder::buildForNode($node) ---

            $controversies = $this->controversyDetector->detect($node, $options->theta);
            $this->logger->info('Controverses détectées', ['count' => \count($controversies)]);

            // Phase B — pistes inexplorées (chaînons manquants, cases creuses, lacunes
            // auto-déclarées). La vérification croisée (§6.5, GapVerifier) viendra ici.
            $gaps = $this->gapDetector->detect($node);
            $this->logger->info('Pistes détectées', ['count' => \count($gaps)]);

            $node->markAnalyzed();
            $this->em->flush();

            return new AnalysisResult(
                publications: $extraction['publications'],
                claims: $extraction['claims'],
                controversies: \count($controversies),
                gaps: \count($gaps),
                axisAppraisals: $axis['appraised'],
            );
        } catch (\Throwable $e) {
            // On journalise l'ERREUR RÉELLE (avec sa trace) AVANT toute autre
            // opération DB, pour ne pas la masquer si l'EntityManager est fermé.
            $this->logger->error('Analyse échouée', ['node' => $node->getSlug(), 'exception' => $e]);
            $this->releaseLock($nodeId);

            throw $e;
        }
    }

    /**
     * Relâche le verrou (repasse le nœud à NotAnalyzed) de façon robuste, même si
     * l'EntityManager a été fermé par l'exception (flush DBAL en échec ⇒ EM clos) :
     * on réinitialise alors le manager pour disposer d'une connexion saine.
     */
    private function releaseLock(int $nodeId): void
    {
        try {
            $em = $this->em;
            if (!$em->isOpen()) {
                $this->registry->resetManager();
                $em = $this->registry->getManager();
            }
            $node = $em->find(TreeNode::class, $nodeId);
            if ($node instanceof TreeNode) {
                $node->setAnalysisStatus(AnalysisStatus::NotAnalyzed);
                $em->flush();
            }
        } catch (\Throwable $e) {
            // Dernier recours : on ne laisse pas l'échec de reset masquer l'origine.
            $this->logger->error('Échec du relâchement du verrou d\'analyse', ['node' => $nodeId, 'error' => $e->getMessage()]);
        }
    }
}
