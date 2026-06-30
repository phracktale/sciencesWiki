<?php

declare(strict_types=1);

namespace App\Analysis\Amstar2;

use App\Ai\Llm\LlmClient;
use App\Catalog\Amstar2Checklist;
use App\Entity\Amstar2Appraisal;
use App\Entity\Publication;
use App\Entity\TreeNode;
use App\Repository\Amstar2AppraisalRepository;
use App\Repository\PublicationRepository;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Évaluation AMSTAR-2 d'une revue systématique. Orchestration LLM → JSON → garde-fou
 * → confiance globale → persistance, calquée sur {@see App\Analysis\Axis\AxisAppraiser}.
 * Verrou d'applicabilité (revues systématiques) ; garde-fou anti-survalorisation (un
 * « oui » sur un domaine CRITIQUE non ancré par une citation présente dans le texte est
 * rétrogradé en « oui partiel ») ; un retry si le JSON est indécodable.
 */
final class Amstar2Appraiser
{
    private const LLM_TIMEOUT = 300;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly Amstar2PromptBuilder $promptBuilder,
        private readonly Amstar2JsonParser $parser,
        private readonly Amstar2AppraisalRepository $appraisals,
        private readonly PublicationRepository $publications,
        private readonly EntityManagerInterface $em,
        private readonly SettingsService $settings,
    ) {
    }

    public function appraiseForPublication(Publication $publication, ?TreeNode $node = null, bool $reappraise = false): ?Amstar2Appraisal
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

        $appraisal = (new Amstar2Appraisal($publication, $this->settings->lightModel()))
            ->setTreeNode($node)
            ->setApplicability($parsed->applicability)
            ->setStudyDesign($parsed->studyDesign)
            ->setSourceScope($scope)
            ->setSummary($parsed->summary);

        if ('not_applicable' === $parsed->applicability) {
            $appraisal->setAnswers([])->setJustifications([])->setScoring(0, 0, null);
            $this->em->persist($appraisal);

            return $appraisal;
        }

        [$answers, $justifications] = $this->applyGuardrail($parsed, $sourceText);
        [$criticalFlaws, $weaknesses] = $this->countFlaws($answers);

        // AMSTAR-2 exige le texte intégral : sur résumé seul, les méthodes (protocole,
        // recherche exhaustive, liste des exclus, RoB…) sont quasi toujours « non
        // rapportées » → notation systématiquement « très faible », trompeuse. On rend
        // donc une confiance INDÉTERMINÉE plutôt qu'un faux verdict accablant.
        $overall = 'abstract' === $scope
            ? 'insufficient'
            : Amstar2Checklist::overall($criticalFlaws, $weaknesses);

        $appraisal
            ->setAnswers($answers)
            ->setJustifications($justifications)
            ->setScoring($criticalFlaws, $weaknesses, $overall);

        $this->em->persist($appraisal);

        return $appraisal;
    }

    /**
     * Garde-fou : on retire les citations non retrouvées dans le texte ; un « oui »
     * sur un DOMAINE CRITIQUE non ancré par une citation est rétrogradé en « oui
     * partiel » (on ne crédite pas une bonne pratique non prouvée). Les items manquants
     * sont comptés « non » (convention AMSTAR-2 : non rapporté = non).
     *
     * @return array{0:array<string,string>,1:array<string,string>}
     */
    private function applyGuardrail(ParsedAmstar2Appraisal $parsed, string $sourceText): array
    {
        $haystack = $this->normalizeText($sourceText);
        $answers = [];
        $justifications = [];

        foreach (Amstar2Checklist::keys() as $key) {
            $answer = $parsed->answers[$key] ?? 'no';
            $quote = $parsed->justifications[$key] ?? null;
            $quoteOk = null !== $quote && $this->quoteExists($quote, $haystack);

            if ('yes' === $answer && Amstar2Checklist::isCritical($key) && !$quoteOk) {
                $answer = 'partial_yes';
            }

            $answers[$key] = $answer;
            if ($quoteOk) {
                $justifications[$key] = (string) $quote;
            }
        }

        return [$answers, $justifications];
    }

    /**
     * @param array<string,string> $answers
     *
     * @return array{0:int,1:int} [défauts critiques, réserves non critiques]
     */
    private function countFlaws(array $answers): array
    {
        $critical = 0;
        $weaknesses = 0;
        foreach ($answers as $key => $answer) {
            if ('no' !== $answer) {
                continue;
            }
            if (Amstar2Checklist::isCritical($key)) {
                ++$critical;
            } else {
                ++$weaknesses;
            }
        }

        return [$critical, $weaknesses];
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

    private function complete(Publication $publication, string $sourceText): ?ParsedAmstar2Appraisal
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
