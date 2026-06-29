<?php

declare(strict_types=1);

namespace App\Tests\Analysis\Axis;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmCompletion;
use App\Analysis\Axis\AxisAppraiser;
use App\Analysis\Axis\AxisJsonParser;
use App\Analysis\Axis\AxisPromptBuilder;
use App\Entity\AxisAppraisal;
use App\Entity\Publication;
use App\Enum\AxisAnswer;
use App\Enum\AxisApplicability;
use App\Repository\AxisAppraisalRepository;
use App\Repository\PublicationRepository;
use App\Repository\SettingRepository;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie le verrou d'applicabilité et le garde-fou anti-hallucination de
 * l'évaluation AXIS (cf. docs/spec-axis-articles.md §3 et §12).
 */
final class AxisAppraiserTest extends TestCase
{
    public function testNonCrossSectionalIsMarkedNotApplicableWithoutItems(): void
    {
        $appraisal = $this->appraise(
            '{"study_design":"rct","applicable":false,"summary":"Essai randomisé : AXIS hors-sujet."}',
            'Cet essai randomisé contrôlé évalue un traitement.',
        );

        self::assertSame(AxisApplicability::NotApplicable, $appraisal->getApplicability());
        self::assertSame([], $appraisal->getAnswers());
        self::assertSame('rct', $appraisal->getStudyDesign());
    }

    public function testUnfavorableAnswerWithVerbatimQuoteIsKept(): void
    {
        $appraisal = $this->appraise(
            '{"study_design":"cross-sectional","applicable":true,"items":{'
            .'"q3":{"answer":"no","quote":"did not report a sample size calculation"}}}',
            'This cross-sectional survey did not report a sample size calculation.',
        );

        self::assertSame(AxisApplicability::Applicable, $appraisal->getApplicability());
        self::assertSame(AxisAnswer::No->value, $appraisal->getAnswers()['q3']);
        self::assertArrayHasKey('q3', $appraisal->getJustifications());
    }

    public function testUnfavorableAnswerWithoutAnchorIsDowngradedToUnclear(): void
    {
        $appraisal = $this->appraise(
            '{"study_design":"cross-sectional","applicable":true,"items":{'
            .'"q3":{"answer":"no","quote":"the authors fabricated the entire dataset"}}}',
            'This cross-sectional survey reports prevalence in a clear way.',
        );

        // La citation n'existe pas dans le texte → réponse défavorable rétrogradée.
        self::assertSame(AxisAnswer::Unclear->value, $appraisal->getAnswers()['q3']);
        self::assertArrayNotHasKey('q3', $appraisal->getJustifications());
    }

    private function appraise(string $llmContent, string $abstract): AxisAppraisal
    {
        $publication = (new Publication('Prevalence of X in a population'))->setAbstract($abstract);

        $appraisals = $this->createStub(AxisAppraisalRepository::class);
        $appraisals->method('findForPublication')->willReturn(null);
        $appraisals->method('deleteForPublication')->willReturn(0);

        $publications = $this->createStub(PublicationRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);

        $settingRepo = $this->createStub(SettingRepository::class);
        $settingRepo->method('allAsMap')->willReturn([]);
        $settings = new SettingsService($settingRepo, $em);

        $appraiser = new AxisAppraiser(
            $this->llm($llmContent),
            new AxisPromptBuilder(),
            new AxisJsonParser(),
            $appraisals,
            $publications,
            $em,
            $settings,
        );

        $appraisal = $appraiser->appraiseForPublication($publication);
        self::assertNotNull($appraisal);

        return $appraisal;
    }

    private function llm(string $content): LlmClient
    {
        return new class($content) implements LlmClient {
            public function __construct(private readonly string $content)
            {
            }

            public function complete(array $messages, array $options = []): LlmCompletion
            {
                return new LlmCompletion($this->content, 'stub', null, null);
            }

            public function stream(array $messages, array $options = []): iterable
            {
                yield $this->content;
            }

            public function model(): string
            {
                return 'stub';
            }
        };
    }
}
