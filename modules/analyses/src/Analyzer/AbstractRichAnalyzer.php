<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\RichFramework;
use Analyses\Framework\RichPromptBuilder;
use Analyses\Sdk\LlmPort;
use Analyses\Service\SettingsService;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Socle des analyseurs « riches » : porte la même machinerie qu'AXIS (prompt calibré item par
 * item + sortie structurée verdict/expected/evidence_found/analysis/limitations/evidence[] +
 * VÉRIFICATION LITTÉRALE des citations et garde-fou d'ancrage) mais pour N'IMPORTE quel
 * {@see RichFramework}. Chaque référentiel ne fournit que son cadrage calibré ; la logique
 * d'exécution, d'ancrage et de dégradation est mutualisée ici.
 */
abstract class AbstractRichAnalyzer implements AnalyzerInterface
{
    use QuoteAnchoring;

    private const CONFIDENCE = ['high', 'medium', 'low'];

    private ?SettingsService $settings = null;

    public function __construct(
        protected readonly LlmPort $llm,
        protected readonly RichPromptBuilder $prompt = new RichPromptBuilder(),
    ) {
    }

    #[Required]
    public function setSettings(SettingsService $settings): void
    {
        $this->settings = $settings;
    }

    /** Référentiel calibré exécuté. */
    abstract protected function framework(): RichFramework;

    public function frameworkId(): string
    {
        return $this->framework()->id();
    }

    public function analyze(string $fulltext, array $meta): array
    {
        $f = $this->framework();
        $model = $this->settings?->analysisModel() ?? 'glm-5.2:cloud';
        $title = \is_string($meta['title'] ?? null) ? (string) $meta['title'] : '';
        $source = '' !== trim($fulltext) ? $fulltext : (string) ($meta['abstract'] ?? '');
        $excerpt = mb_substr($source, 0, 20000);

        // Prompt volumineux + sortie riche (jusqu'à ~22 items) : génération longue. En
        // stream:false le « timeout » HttpClient = budget total ; on laisse une marge large.
        $out = $this->llm->generateJson(
            $this->prompt->user($title, $excerpt),
            $model,
            900,
            $this->prompt->system($f),
        );

        $summary = $this->str($out['summary'] ?? null);
        $applicable = $this->applicable($out, $f);

        if (null !== $f->applicabilityNote() && false === $applicable) {
            return [
                'criteria' => [],
                'overall' => [
                    'framework_id' => $f->id(), 'model' => $model, 'applicable' => false,
                    'summary' => $summary, 'coverage' => 0.0, 'human_review' => true,
                ],
            ];
        }

        $items = \is_array($out['items'] ?? null) ? $out['items'] : [];
        $nonConclusive = array_merge($f->nonConclusiveAnswers(), ['na']);
        $validAnswers = array_keys($f->answerScale());
        $unclear = $f->unclearAnswer();

        $criteria = [];
        $answered = 0;
        $downgraded = 0;
        foreach ($f->richItems() as $it) {
            $entry = \is_array($items[$it['id']] ?? null) ? $items[$it['id']] : [];
            $criteria[] = $c = $this->criterion($it, $entry, $excerpt, $validAnswers, $nonConclusive, $unclear);
            if (!\in_array($c['answer'], $nonConclusive, true)) {
                ++$answered;
            }
            if ($c['requires_human_review']) {
                ++$downgraded;
            }
        }

        $total = \count($criteria);
        $coverage = $total > 0 ? round($answered / $total, 2) : 0.0;

        $overall = [
            'framework_id' => $f->id(), 'model' => $model, 'summary' => $summary,
            'coverage' => $coverage, 'answered' => $answered, 'downgraded' => $downgraded,
            'human_review' => $coverage < 0.6 || $downgraded > (int) ceil($total * 0.35),
        ];
        if (null !== $f->applicabilityNote()) {
            $overall['applicable'] = $applicable ?? true;
        }

        return ['criteria' => $criteria, 'overall' => $overall];
    }

    /**
     * @param array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string} $it
     * @param array<string, mixed>                                                                                                                                                                      $entry
     * @param list<string>                                                                                                                                                                              $validAnswers
     * @param list<string>                                                                                                                                                                              $nonConclusive
     *
     * @return array<string, mixed>
     */
    private function criterion(array $it, array $entry, string $text, array $validAnswers, array $nonConclusive, string $unclear): array
    {
        $answer = \in_array($entry['answer'] ?? null, $validAnswers, true) ? (string) $entry['answer'] : $unclear;
        $confidence = \in_array($entry['confidence'] ?? null, self::CONFIDENCE, true) ? (string) $entry['confidence'] : 'low';
        $overallType = $this->str($entry['overall_evidence_type'] ?? null);
        $rawEvidence = $this->evidenceList($entry['evidence'] ?? null);

        // Vérification LITTÉRALE des citations : une preuve citée doit exister dans le texte.
        $evidence = [];
        $hasVerifiedQuote = false;
        $verifiedAbsence = null !== $overallType && str_contains($overallType, 'absence_from_full_text');
        $flatQuote = null;
        foreach ($rawEvidence as $e) {
            $et = $e['evidence_type'];
            if (null !== $e['quote'] && \in_array($et, ['explicit_quote', 'visual_table', 'visual_figure'], true)) {
                if ($this->quoteInText($e['quote'], $text)) {
                    $hasVerifiedQuote = true;
                    $flatQuote ??= $e['quote'];
                } else {
                    $e['evidence_type'] = 'unverified_quote';
                }
            } elseif ('absence_from_full_text' === $et) {
                $verifiedAbsence = true;
            }
            $evidence[] = $e;
        }

        // Garde-fou : une réponse conclusive doit être ancrée (citation vérifiée OU absence vérifiée).
        $requiresReview = (bool) ($entry['requires_visual_check'] ?? false);
        if (!\in_array($answer, $nonConclusive, true) && !$hasVerifiedQuote && !$verifiedAbsence) {
            $answer = $unclear;
            $requiresReview = true;
        }
        if ('inference' === $overallType && 'high' === $confidence) {
            $confidence = 'medium';
        }

        $effectiveType = $hasVerifiedQuote ? 'explicit_quote'
            : ($verifiedAbsence ? 'absence_from_full_text' : ($overallType ?? 'absence_from_extracted_text_only'));

        return [
            'criterion_id' => $it['id'],
            'dimension' => $it['section'],
            'question' => $it['question'],
            'answer' => $answer,
            'verdict' => $this->str($entry['verdict'] ?? null),
            'expected' => $this->str($entry['expected'] ?? null) ?? $it['expected'],
            'evidence_found' => $this->str($entry['evidence_found'] ?? null),
            'analysis' => $this->str($entry['analysis'] ?? ($entry['reasoning'] ?? null)),
            'limitations' => $this->str($entry['limitations'] ?? null),
            'evidence_type' => $effectiveType,
            'overall_evidence_type' => $overallType,
            'confidence' => $confidence,
            'quote' => $flatQuote,
            'requires_visual_check' => (bool) ($entry['requires_visual_check'] ?? false),
            'requires_human_review' => $requiresReview,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<string, mixed> $out
     */
    private function applicable(array $out, RichFramework $f): ?bool
    {
        if (null === $f->applicabilityNote()) {
            return true;
        }
        if (\array_key_exists('applicable', $out)) {
            return (bool) $out['applicable'];
        }

        return null;
    }

    /**
     * @return list<array{source_type: ?string, section: ?string, quote: ?string, evidence_type: ?string}>
     */
    private function evidenceList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $e) {
            if (!\is_array($e)) {
                continue;
            }
            $quote = $this->str($e['quote'] ?? null);
            $etype = $this->str($e['evidence_type'] ?? null);
            if (null === $quote && null === $etype) {
                continue;
            }
            $out[] = [
                'source_type' => $this->str($e['source_type'] ?? null),
                'section' => $this->str($e['section'] ?? null),
                'quote' => $quote,
                'evidence_type' => $etype,
            ];
            if (\count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    private function str(mixed $v): ?string
    {
        if (!\is_string($v) && !\is_numeric($v)) {
            return null;
        }
        $s = trim((string) $v);

        return '' === $s || 'null' === strtolower($s) ? null : $s;
    }
}
