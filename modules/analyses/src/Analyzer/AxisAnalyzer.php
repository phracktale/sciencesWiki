<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Axis\AxisAnswer;
use Analyses\Framework\Axis\AxisChecklist;
use Analyses\Framework\Axis\AxisPromptBuilder;
use Analyses\Sdk\LlmPort;
use Analyses\Service\SettingsService;

/**
 * Analyseur AXIS RICHE — reprend le système d'analyse legacy de SciencesWiki
 * (prompt calibré item par item + sortie structurée : verdict/expected/evidence_found/
 * analysis/limitations/evidence[]/overall_evidence_type/requires_visual_check + étape 0
 * d'applicabilité + summary). Y ajoute la VÉRIFICATION LITTÉRALE des citations du module
 * (garde-fou d'ancrage), plus stricte que le legacy.
 */
final class AxisAnalyzer implements AnalyzerInterface
{
    use QuoteAnchoring;

    private const CONFIDENCE = ['high', 'medium', 'low'];

    public function __construct(
        private readonly LlmPort $llm,
        private readonly SettingsService $settings,
        private readonly AxisPromptBuilder $prompt = new AxisPromptBuilder(),
    ) {
    }

    public function frameworkId(): string
    {
        return 'axis';
    }

    public function analyze(string $fulltext, array $meta): array
    {
        $model = $this->settings->analysisModel();
        $title = \is_string($meta['title'] ?? null) ? (string) $meta['title'] : '';
        $source = '' !== trim($fulltext) ? $fulltext : (string) ($meta['abstract'] ?? '');
        $excerpt = mb_substr($source, 0, 20000);

        $out = $this->llm->generateJson(
            $this->prompt->user($title, $excerpt),
            $model,
            240,
            $this->prompt->system(),
        );

        $applicable = $this->applicable($out);
        $summary = $this->str($out['summary'] ?? null);

        if (false === $applicable) {
            return [
                'criteria' => [],
                'overall' => [
                    'framework_id' => 'axis', 'model' => $model, 'applicable' => false,
                    'summary' => $summary, 'coverage' => 0.0, 'human_review' => true,
                ],
            ];
        }

        $items = \is_array($out['items'] ?? null) ? $out['items'] : [];
        $criteria = [];
        $answered = 0;
        $downgraded = 0;
        foreach (AxisChecklist::ITEMS as $key => $meta2) {
            $entry = \is_array($items[$key] ?? null) ? $items[$key] : [];
            $criteria[] = $c = $this->criterion($key, $meta2, $entry, $excerpt);
            if (!\in_array($c['answer'], ['unclear', 'na'], true)) {
                ++$answered;
            }
            if ($c['requires_human_review']) {
                ++$downgraded;
            }
        }

        $coverage = round($answered / \count(AxisChecklist::ITEMS), 2);

        return [
            'criteria' => $criteria,
            'overall' => [
                'framework_id' => 'axis', 'model' => $model, 'applicable' => $applicable ?? true,
                'summary' => $summary, 'coverage' => $coverage, 'answered' => $answered, 'downgraded' => $downgraded,
                'human_review' => $coverage < 0.6 || $downgraded > 6,
            ],
        ];
    }

    /**
     * @param array{section: string, text: string, help: string} $meta
     * @param array<string, mixed>                                $entry
     *
     * @return array<string, mixed>
     */
    private function criterion(string $key, array $meta, array $entry, string $text): array
    {
        $answer = (AxisAnswer::tryFrom((string) ($entry['answer'] ?? '')) ?? AxisAnswer::Unclear)->value;
        $confidence = \in_array($entry['confidence'] ?? null, self::CONFIDENCE, true) ? (string) $entry['confidence'] : 'low';
        $overallType = $this->str($entry['overall_evidence_type'] ?? null);
        $rawEvidence = $this->evidenceList($entry['evidence'] ?? null);

        // Vérification LITTÉRALE des citations (module) : une preuve citée doit exister
        // dans le texte, sinon elle est marquée non vérifiée et ne compte pas comme ancrage.
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
        if (\in_array($answer, ['yes', 'partial', 'no'], true) && !$hasVerifiedQuote && !$verifiedAbsence) {
            $answer = 'unclear';
            $requiresReview = true;
        }
        if ('inference' === $overallType && 'high' === $confidence) {
            $confidence = 'medium';
        }

        $effectiveType = $hasVerifiedQuote ? 'explicit_quote'
            : ($verifiedAbsence ? 'absence_from_full_text' : ($overallType ?? 'absence_from_extracted_text_only'));

        return [
            'criterion_id' => 'axis.'.$key,
            'dimension' => $meta['section'],
            'question' => $meta['text'],
            'answer' => $answer,
            'verdict' => $this->str($entry['verdict'] ?? null),
            'expected' => $this->str($entry['expected'] ?? null),
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

    /** @param array<string, mixed> $out */
    private function applicable(array $out): ?bool
    {
        if (\array_key_exists('applicable', $out)) {
            return (bool) $out['applicable'];
        }
        $design = strtolower($this->str($out['study_design'] ?? null) ?? '');
        if ('' === $design) {
            return null;
        }

        return str_contains($design, 'cross') || str_contains($design, 'transvers');
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
