<?php

declare(strict_types=1);

namespace App\Analysis\Rob2;

use App\Ai\Llm\LlmClient;
use App\Catalog\Rob2Checklist;
use App\Entity\Publication;
use App\Entity\Rob2Appraisal;
use App\Entity\TreeNode;
use App\Repository\PublicationRepository;
use App\Repository\Rob2AppraisalRepository;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Évaluation RoB 2 d'une publication (essais randomisés). Orchestration LLM → JSON →
 * garde-fou → jugement global → persistance, calquée sur {@see App\Analysis\Axis
 * \AxisAppraiser}. Verrou d'applicabilité (RCT seulement) ; garde-fou anti-hallucination
 * (un domaine « risque élevé » non ancré par une citation présente dans le texte est
 * rétrogradé en « quelques réserves ») ; un retry si le JSON est indécodable.
 */
final class Rob2Appraiser
{
    private const LLM_TIMEOUT = 300;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly Rob2PromptBuilder $promptBuilder,
        private readonly Rob2JsonParser $parser,
        private readonly Rob2AppraisalRepository $appraisals,
        private readonly PublicationRepository $publications,
        private readonly EntityManagerInterface $em,
        private readonly SettingsService $settings,
    ) {
    }

    public function appraiseForPublication(Publication $publication, ?TreeNode $node = null, bool $reappraise = false): ?Rob2Appraisal
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
            return null;
        }

        $parsed = $this->complete($publication, $sourceText);
        if (null === $parsed) {
            return null;
        }

        $appraisal = (new Rob2Appraisal($publication, $this->settings->lightModel()))
            ->setTreeNode($node)
            ->setApplicability($parsed->applicability)
            ->setStudyDesign($parsed->studyDesign)
            ->setSourceScope($scope)
            ->setSummary($parsed->summary);

        if ('not_applicable' === $parsed->applicability) {
            // Verrou : la grille n'est pas exécutée pour un design non randomisé.
            $appraisal->setDomains([])->setOverall(null);
            $this->em->persist($appraisal);

            return $appraisal;
        }

        $domains = $this->applyGuardrail($parsed->domains, $sourceText);
        $appraisal->setDomains($domains)->setOverall($this->overall($domains));
        $this->em->persist($appraisal);

        return $appraisal;
    }

    /**
     * Garde-fou anti-hallucination : un domaine jugé « high » dont la citation n'est
     * PAS retrouvée verbatim dans le texte est rétrogradé en « some_concerns » (on
     * suspecte sans pouvoir confirmer). Les citations non retrouvées sont retirées.
     *
     * @param array<string,array{judgement:string,quote:?string,rationale:?string}> $parsed
     *
     * @return array<string,array{judgement:string,quote:?string,rationale:?string}>
     */
    private function applyGuardrail(array $parsed, string $sourceText): array
    {
        $haystack = $this->normalizeText($sourceText);
        $out = [];
        foreach (Rob2Checklist::keys() as $key) {
            $entry = $parsed[$key] ?? ['judgement' => 'some_concerns', 'quote' => null, 'rationale' => null];
            $judgement = $entry['judgement'];
            $quote = $entry['quote'];
            $quoteOk = null !== $quote && $this->quoteExists($quote, $haystack);

            if ('high' === $judgement && !$quoteOk) {
                $judgement = 'some_concerns';
                $quote = null;
            } elseif (!$quoteOk) {
                $quote = null;
            }

            $out[$key] = ['judgement' => $judgement, 'quote' => $quote, 'rationale' => $entry['rationale']];
        }

        return $out;
    }

    /**
     * Jugement GLOBAL (cf. algorithme RoB 2, version simplifiée) : « high » si un
     * domaine au moins est élevé ; sinon « some_concerns » si réserve sur un domaine ;
     * sinon « low ». « insufficient » si aucun domaine n'a pu être jugé.
     *
     * @param array<string,array{judgement:string,quote:?string,rationale:?string}> $domains
     */
    private function overall(array $domains): string
    {
        if ([] === $domains) {
            return 'insufficient';
        }
        $judgements = array_column($domains, 'judgement');
        if (\in_array('high', $judgements, true)) {
            return 'high';
        }
        if (\in_array('some_concerns', $judgements, true)) {
            return 'some_concerns';
        }

        return 'low';
    }

    /** @return array{0:string,1:string} */
    private function sourceText(Publication $publication): array
    {
        $abstract = trim((string) ($publication->getAbstract() ?? $publication->getAbstractFr() ?? ''));

        if ($publication->isFulltextStored()) {
            $fulltext = $this->publications->fulltextFor((int) $publication->getId());
            if ('' !== trim($fulltext)) {
                return [trim($abstract."\n\n".$fulltext), 'abstract+fulltext'];
            }
        }

        return [$abstract, 'abstract'];
    }

    private function complete(Publication $publication, string $sourceText): ?ParsedRob2Appraisal
    {
        $messages = $this->promptBuilder->build($publication, $sourceText);
        $opts = ['temperature' => 0.0, 'max_tokens' => 1500, 'model' => $this->settings->lightModel(), 'timeout' => self::LLM_TIMEOUT];

        $parsed = $this->parser->parse($this->llm->complete($messages, $opts)->content);
        if (null === $parsed) {
            $parsed = $this->parser->parse($this->llm->complete($messages, $opts)->content);
        }

        return $parsed;
    }

    private function quoteExists(string $quote, string $normalizedHaystack): bool
    {
        $needle = $this->normalizeText($quote);
        if (mb_strlen($needle) < 8) {
            return false;
        }

        return str_contains($normalizedHaystack, $needle);
    }

    private function normalizeText(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
