<?php

declare(strict_types=1);

namespace App\Analysis\Mmat;

use App\Ai\Llm\LlmClient;
use App\Catalog\MmatChecklist;
use App\Entity\MmatAppraisal;
use App\Entity\Publication;
use App\Entity\TreeNode;
use App\Repository\MmatAppraisalRepository;
use App\Repository\PublicationRepository;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Évaluation MMAT d'une étude empirique. Orchestration LLM → JSON → garde-fou → repère
 * de qualité indicatif → persistance, calquée sur {@see App\Analysis\Amstar2\Amstar2Appraiser}.
 * Verrou d'applicabilité (études empiriques) ; garde-fou anti-survalorisation (un « oui »
 * sur un critère non ancré par une citation présente dans le texte est rétrogradé en
 * « impossible à déterminer ») ; un retry si le JSON est indécodable.
 */
final class MmatAppraiser
{
    private const LLM_TIMEOUT = 300;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly MmatPromptBuilder $promptBuilder,
        private readonly MmatJsonParser $parser,
        private readonly MmatAppraisalRepository $appraisals,
        private readonly PublicationRepository $publications,
        private readonly EntityManagerInterface $em,
        private readonly SettingsService $settings,
    ) {
    }

    public function appraiseForPublication(Publication $publication, ?TreeNode $node = null, bool $reappraise = false): ?MmatAppraisal
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

        $appraisal = (new MmatAppraisal($publication, $this->settings->lightModel()))
            ->setTreeNode($node)
            ->setApplicability($parsed->applicability)
            ->setCategory($parsed->category)
            ->setStudyDesign($parsed->studyDesign)
            ->setSourceScope($scope)
            ->setSummary($parsed->summary);

        if ('applicable' !== $parsed->applicability) {
            $appraisal->setAnswers([])->setJustifications([])->setScoring(false, 0, null);
            $this->em->persist($appraisal);

            return $appraisal;
        }

        [$answers, $justifications] = $this->applyGuardrail($parsed, $sourceText);
        $screeningPassed = 'yes' === ($answers['s1'] ?? '') && 'yes' === ($answers['s2'] ?? '');
        $metCount = $this->countMet($answers);

        // MMAT exige le texte intégral : sur résumé seul, plusieurs critères (randomisation,
        // aveugle, complétude des données, confusion…) sont quasi toujours « impossible à
        // déterminer » → repère systématiquement bas, trompeur. On rend donc une qualité
        // INDÉTERMINÉE plutôt qu'un faux verdict accablant.
        $overall = 'abstract' === $scope
            ? 'insufficient'
            : MmatChecklist::overall($metCount);

        $appraisal
            ->setAnswers($answers)
            ->setJustifications($justifications)
            ->setScoring($screeningPassed, $metCount, $overall);

        $this->em->persist($appraisal);

        return $appraisal;
    }

    /**
     * Garde-fou : on retire les citations non retrouvées dans le texte ; un « oui » sur un
     * CRITÈRE (c1…c5) non ancré par une citation est rétrogradé en « impossible à
     * déterminer » (on ne crédite pas une force non prouvée). Les questions de filtrage
     * (s1/s2) ne sont pas rétrogradées (elles portent sur la clarté, pas la méthode).
     *
     * @return array{0:array<string,string>,1:array<string,string>}
     */
    private function applyGuardrail(ParsedMmatAppraisal $parsed, string $sourceText): array
    {
        $haystack = $this->normalizeText($sourceText);
        $answers = [];
        $justifications = [];

        $criteria = MmatChecklist::criterionKeys();
        foreach (array_merge(MmatChecklist::screeningKeys(), $criteria) as $key) {
            $answer = $parsed->answers[$key] ?? 'cant_tell';
            $quote = $parsed->justifications[$key] ?? null;
            $quoteOk = null !== $quote && $this->quoteExists($quote, $haystack);

            if ('yes' === $answer && \in_array($key, $criteria, true) && !$quoteOk) {
                $answer = 'cant_tell';
            }

            $answers[$key] = $answer;
            if ($quoteOk) {
                $justifications[$key] = (string) $quote;
            }
        }

        return [$answers, $justifications];
    }

    /**
     * Nombre de critères remplis (« oui ») parmi les cinq (c1…c5) — hors filtrage.
     *
     * @param array<string,string> $answers
     */
    private function countMet(array $answers): int
    {
        $met = 0;
        foreach (MmatChecklist::criterionKeys() as $key) {
            if ('yes' === ($answers[$key] ?? '')) {
                ++$met;
            }
        }

        return $met;
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

    private function complete(Publication $publication, string $sourceText): ?ParsedMmatAppraisal
    {
        $messages = $this->promptBuilder->build($publication, $sourceText);
        $opts = ['temperature' => 0.0, 'max_tokens' => 2000, 'model' => $this->settings->lightModel(), 'timeout' => self::LLM_TIMEOUT];

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
