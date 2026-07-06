<?php

declare(strict_types=1);

namespace App\Analysis\Axis;

use App\Ai\Llm\LlmClient;
use App\Catalog\AxisChecklist;
use App\Entity\AxisAppraisal;
use App\Entity\Publication;
use App\Entity\TreeNode;
use App\Enum\AxisAnswer;
use App\Enum\AxisApplicability;
use App\Repository\AxisAppraisalRepository;
use App\Repository\PublicationRepository;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Évaluation AXIS d'une publication (cf. docs/spec-axis-articles.md). Orchestration
 * LLM → JSON → garde-fou → persistance, calquée sur {@see App\Analysis\Claim
 * \ClaimExtractor}.
 *
 * Garanties : modèle figé, idempotence (purge avant ré-insert), verrou
 * d'applicabilité (transversales seulement), garde-fou anti-hallucination (toute
 * réponse défavorable doit être ancrée par une citation présente dans le texte,
 * sinon « indéterminé »), un retry si le JSON est indécodable.
 */
final class AxisAppraiser
{
    private const FLUSH_EVERY = 10;
    // Large : sur le LLM auto-hébergé (Marvin, CPU), une évaluation complète (texte
    // intégral + 20 items) peut durer 6-10 min ; le timeout d'inactivité doit couvrir
    // toute la génération (réponse non streamée = aucun octet avant la fin).
    private const LLM_TIMEOUT = 900;

    /** En deçà de ce nombre d'items évaluables, le texte est jugé insuffisant. */
    private const MIN_ASSESSABLE = 10;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly AxisPromptBuilder $promptBuilder,
        private readonly AxisJsonParser $parser,
        private readonly AxisAppraisalRepository $appraisals,
        private readonly PublicationRepository $publications,
        private readonly EntityManagerInterface $em,
        private readonly SettingsService $settings,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Évalue les publications validées d'un nœud.
     *
     * @return array{publications:int,appraised:int,applicable:int}
     */
    public function appraiseForNode(TreeNode $node, int $limit = 1000, bool $reappraise = false): array
    {
        $publications = $this->publications->findPlacedInNode((int) $node->getId(), $limit);

        $appraised = 0;
        $applicable = 0;
        $done = 0;
        foreach ($publications as $i => $publication) {
            try {
                $appraisal = $this->appraiseForPublication($publication, $node, $reappraise);
                if (null !== $appraisal) {
                    ++$appraised;
                    if (AxisApplicability::NotApplicable !== $appraisal->getApplicability()) {
                        ++$applicable;
                    }
                }
            } catch (\Throwable $e) {
                // Échec d'UNE publication (timeout LLM…) : on journalise et on poursuit,
                // l'EntityManager reste ouvert (pas d'erreur DB).
                $this->logger->warning('Évaluation AXIS ignorée pour une publication', [
                    'publication' => $publication->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
            ++$done;
            if (0 === ($i + 1) % self::FLUSH_EVERY) {
                $this->em->flush();
            }
        }
        $this->em->flush();

        return ['publications' => $done, 'appraised' => $appraised, 'applicable' => $applicable];
    }

    /**
     * Évalue et persiste l'AXIS d'une publication. Renvoie l'entité créée, ou null
     * si le LLM n'a rien produit d'exploitable. En mode incrémental
     * (`$reappraise = false`), une publication déjà évaluée est ignorée (renvoie
     * l'évaluation existante).
     */
    public function appraiseForPublication(Publication $publication, ?TreeNode $node = null, bool $reappraise = false): ?AxisAppraisal
    {
        if (!$reappraise) {
            $existing = $this->appraisals->findForPublication($publication);
            if (null !== $existing) {
                return $existing;
            }
        }
        $this->appraisals->deleteForPublication($publication);

        [$sourceText, $scope] = $this->sourceText($publication);
        if ('' === trim($sourceText)) {
            return null; // ni résumé ni texte intégral : rien à évaluer.
        }

        $parsed = $this->complete($publication, $sourceText);
        if (null === $parsed) {
            return null;
        }

        $model = $this->settings->appraisalModel();
        $appraisal = (new AxisAppraisal($publication, $model))
            ->setTreeNode($node)
            ->setApplicability($parsed->applicability)
            ->setStudyDesign($parsed->studyDesign)
            ->setSourceScope($scope)
            ->setSummary($parsed->summary);

        if (AxisApplicability::NotApplicable === $parsed->applicability) {
            // Verrou : la grille n'est pas exécutée pour un design non transversal.
            $appraisal->setScoring(0, 0, null);
            $this->em->persist($appraisal);

            return $appraisal;
        }

        [$answers, $justifications] = $this->applyGuardrail($parsed, $sourceText);
        $favorable = $this->favorableCount($answers);
        $assessable = \count(array_filter($answers, static fn (AxisAnswer $a): bool => AxisAnswer::Unclear !== $a));

        $appraisal
            ->setAnswers(array_map(static fn (AxisAnswer $a): string => $a->value, $answers))
            ->setJustifications($justifications)
            ->setScoring($favorable, $assessable, $this->band($favorable, $assessable, $scope));

        $this->em->persist($appraisal);

        return $appraisal;
    }

    /**
     * Conserve la réponse ET la réflexion de chaque item (justification systématique,
     * comme une revue par un expert). Le garde-fou anti-hallucination ne SUPPRIME plus
     * la réponse : il vérifie seulement si la citation est réellement présente dans le
     * texte (verbatim) et ne conserve alors que les citations ancrées ; la réflexion du
     * modèle reste toujours affichée (traçabilité). « anchored » signale les items étayés
     * par une citation exacte.
     *
     * @return array{0:array<string,AxisAnswer>,1:array<string,array{reasoning:?string,quote:?string,anchored:bool}>}
     */
    private function applyGuardrail(ParsedAxisAppraisal $parsed, string $sourceText): array
    {
        $haystack = $this->normalizeText($sourceText);
        $answers = [];
        $justifications = [];

        foreach (AxisChecklist::keys() as $key) {
            $answer = $parsed->answers[$key] ?? AxisAnswer::Unclear;
            $detail = $parsed->justifications[$key] ?? ['reasoning' => null, 'quote' => null];
            $reasoning = \is_array($detail) ? ($detail['reasoning'] ?? null) : (\is_string($detail) ? $detail : null);
            $quote = \is_array($detail) ? ($detail['quote'] ?? null) : null;

            $anchored = null !== $quote && $this->quoteExists($quote, $haystack);
            $answers[$key] = $answer;
            $justifications[$key] = [
                'reasoning' => $reasoning,
                'quote' => $anchored ? $quote : null,
                'anchored' => $anchored,
            ];
        }

        return [$answers, $justifications];
    }

    /** @param array<string,AxisAnswer> $answers */
    private function favorableCount(array $answers): int
    {
        $n = 0;
        foreach ($answers as $key => $answer) {
            if (AxisChecklist::isFavorable($key, $answer)) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Bande indicative de fiabilité (cf. §2 : PAS un score). « insufficient » si
     * le texte ne permet pas d'évaluer assez d'items (résumé seul, typiquement).
     */
    private function band(int $favorable, int $assessable, string $scope): string
    {
        if ($assessable < self::MIN_ASSESSABLE) {
            return 'insufficient';
        }
        $frac = $favorable / $assessable;
        if ($frac >= 0.8) {
            return 'high';
        }
        if ($frac >= 0.6) {
            return 'moderate';
        }

        return 'low';
    }

    /**
     * Texte source : résumé (langue d'origine, repli FR) + extrait du texte intégral
     * GROBID s'il est conservé (les 20 items exigent méthodes/résultats). Renvoie le
     * texte et l'étendue exploitée pour la traçabilité.
     *
     * @return array{0:string,1:string}
     */
    private function sourceText(Publication $publication): array
    {
        $abstract = trim((string) ($publication->getAbstract() ?? $publication->getAbstractFr() ?? ''));

        if ($publication->isFulltextStored()) {
            // Texte intégral large (jusqu'à 50 000 car.) : les modèles ont un grand
            // contexte, et une évaluation fiable des 20 items exige méthodes + résultats
            // + discussion (au-delà des ~16 k = souvent tronqué au milieu des méthodes).
            $fulltext = $this->publications->fulltextFor((int) $publication->getId(), 50000);
            if ('' !== trim($fulltext)) {
                return [trim($abstract."\n\n".$fulltext), 'abstract+fulltext'];
            }
        }

        return [$abstract, 'abstract'];
    }

    /**
     * Appelle le LLM (température 0) et parse, avec UN retry si le JSON est
     * indécodable.
     */
    private function complete(Publication $publication, string $sourceText): ?ParsedAxisAppraisal
    {
        $messages = $this->promptBuilder->build($publication, $sourceText);
        $opts = ['temperature' => 0.0, 'max_tokens' => 4000, 'model' => $this->settings->appraisalModel(), 'timeout' => self::LLM_TIMEOUT, 'json' => true];

        $parsed = $this->parser->parse($this->llm->complete($messages, $opts)->content);
        if (null === $parsed) {
            $parsed = $this->parser->parse($this->llm->complete($messages, $opts)->content);
        }

        return $parsed;
    }

    /** La citation (verbatim attendu) apparaît-elle dans le texte source ? */
    private function quoteExists(string $quote, string $normalizedHaystack): bool
    {
        $needle = $this->normalizeText($quote);
        if (mb_strlen($needle) < 8) {
            return false;
        }

        return str_contains($normalizedHaystack, $needle);
    }

    /** Minuscule, ponctuation→espace, espaces compactés (comparaison robuste). */
    private function normalizeText(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
