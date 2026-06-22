<?php

declare(strict_types=1);

namespace App\Analysis\Claim;

use App\Ai\Llm\LlmClient;
use App\Entity\Claim;
use App\Entity\Publication;
use App\Entity\TreeNode;
use App\Harvester\Ai\EmbeddingClientFactory;
use App\Repository\ClaimRepository;
use App\Repository\PublicationRepository;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestration LLM → JSON → persistance des assertions structurées d'une
 * publication (cf. docs/spec-controverses-lacunes.md §5).
 *
 * Garanties : modèle figé à l'extraction, idempotence (purge avant ré-insert),
 * garde-fou anti-hallucination (la quote doit exister dans le texte source,
 * §12), un retry si le JSON est indécodable.
 */
final class ClaimExtractor
{
    private const FLUSH_EVERY = 25;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly ClaimPromptBuilder $promptBuilder,
        private readonly ClaimJsonParser $parser,
        private readonly EmbeddingClientFactory $embeddingFactory,
        private readonly ClaimRepository $claims,
        private readonly PublicationRepository $publications,
        private readonly EntityManagerInterface $em,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Extrait les claims de toutes les publications validées d'un nœud.
     *
     * @return array{publications:int,claims:int}
     */
    public function extractForNode(TreeNode $node, int $limit = 1000, bool $reextract = false): array
    {
        $publications = $this->publications->findPlacedInNode((int) $node->getId(), $limit);

        $claims = 0;
        $done = 0;
        foreach ($publications as $i => $publication) {
            $claims += $this->extractForPublication($publication, $node, $reextract);
            ++$done;
            if (0 === ($i + 1) % self::FLUSH_EVERY) {
                $this->em->flush();
            }
        }
        $this->em->flush();

        return ['publications' => $done, 'claims' => $claims];
    }

    /**
     * Extrait et persiste les claims d'une publication. Renvoie le nombre créé.
     *
     * En mode incrémental (`$reextract = false`), une publication déjà traitée
     * est ignorée (cf. ré-analyse Stale, spec §0.2). Sinon ses claims sont
     * purgés puis ré-insérés (idempotence, §5.3).
     */
    public function extractForPublication(Publication $publication, ?TreeNode $node = null, bool $reextract = false): int
    {
        if (!$reextract && $this->claims->hasClaimsFor($publication)) {
            return 0;
        }
        $this->claims->deleteForPublication($publication);

        $parsed = $this->complete($publication);
        if (null === $parsed || [] === $parsed) {
            return 0;
        }

        $model = $this->settings->model() ?? $this->llm->model();
        $haystack = $this->normalizeText(
            $publication->getTitle().' '
            .($publication->getAbstract() ?? '').' '
            .($publication->getAbstractFr() ?? ''),
        );

        $created = 0;
        foreach ($parsed as $entry) {
            // Garde-fou anti-hallucination : sans ancrage verbatim, on rejette.
            if (!$this->quoteExists($entry->quote, $haystack)) {
                continue;
            }
            $this->em->persist($this->toClaim($publication, $node, $entry, $model));
            ++$created;
        }

        return $created;
    }

    /**
     * Appelle le LLM (température 0) et parse, avec UN retry si le JSON est
     * indécodable. Renvoie null seulement si le second essai échoue aussi.
     *
     * @return list<ParsedClaim>|null
     */
    private function complete(Publication $publication): ?array
    {
        $messages = $this->promptBuilder->build($publication, $this->conclusion($publication));
        $opts = ['temperature' => 0.0, 'max_tokens' => 1500];
        if (null !== ($m = $this->settings->model())) {
            $opts['model'] = $m;
        }

        $parsed = $this->parser->parse($this->llm->complete($messages, $opts)->content);
        if (null === $parsed) {
            $parsed = $this->parser->parse($this->llm->complete($messages, $opts)->content);
        }

        return $parsed;
    }

    private function toClaim(Publication $publication, ?TreeNode $node, ParsedClaim $entry, string $model): Claim
    {
        $exposureNorm = $this->normalizeKey($entry->exposure);
        $outcomeNorm = $this->normalizeKey($entry->outcome);

        $claim = (new Claim($publication, $model))
            ->setTreeNode($node)
            ->setExposureLabel($entry->exposure)
            ->setOutcomeLabel($entry->outcome)
            ->setExposureNorm($exposureNorm)
            ->setOutcomeNorm($outcomeNorm)
            ->setDirection($entry->direction)
            ->setMethod($entry->method)
            ->setConfidence($entry->confidence)
            ->setPopulation($entry->population)
            ->setSampleSize($entry->sampleSize)
            ->setEffectSize($entry->effectSize)
            ->setStatedLimitations($entry->statedLimitations)
            ->setFutureWork($entry->futureWork)
            ->setQuote($entry->quote)
            ->setEmbedding($this->embeddingFactory->create()->embed($entry->exposure.' → '.$entry->outcome));

        $claim->setRaw([
            'exposure' => $entry->exposure,
            'outcome' => $entry->outcome,
            'direction' => $entry->direction->value,
            'method' => $entry->method->value,
            'confidence' => $entry->confidence->value,
            'population' => $entry->population,
            'sample_size' => $entry->sampleSize,
            'effect_size' => $entry->effectSize,
            'stated_limitations' => $entry->statedLimitations,
            'future_work' => $entry->futureWork,
            'quote' => $entry->quote,
        ]);

        return $claim;
    }

    /**
     * Conclusion GROBID (si full-text conservé). Phase A : non disponible —
     * l'extraction s'appuie sur titre + résumé. Point d'extension Phase B+.
     */
    private function conclusion(Publication $publication): ?string
    {
        return null;
    }

    /**
     * Clé normalisée pour le GROUP BY exact (cf. spec §5.2) : minuscules, sans
     * ponctuation, articles retirés, espaces compactés. Déterministe.
     */
    private function normalizeKey(string $s): string
    {
        $s = $this->normalizeText($s);
        $articles = ['the', 'a', 'an', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'l', 'd'];
        $tokens = array_values(array_filter(
            explode(' ', $s),
            static fn (string $t): bool => '' !== $t && !\in_array($t, $articles, true),
        ));

        return mb_substr(implode(' ', $tokens), 0, 255);
    }

    /** Minuscule, ponctuation→espace, espaces compactés (comparaison robuste). */
    private function normalizeText(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** La quote (verbatim attendu) apparaît-elle dans le texte source ? */
    private function quoteExists(string $quote, string $normalizedHaystack): bool
    {
        $needle = $this->normalizeText($quote);
        // Une quote trop courte n'a pas de valeur probante.
        if (mb_strlen($needle) < 8) {
            return false;
        }

        return str_contains($normalizedHaystack, $needle);
    }
}
