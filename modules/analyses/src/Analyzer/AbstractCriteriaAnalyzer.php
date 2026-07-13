<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Sdk\LlmPort;
use Analyses\Service\SettingsService;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Socle commun aux analyseurs « par critères » (AXIS, RoB 2, AMSTAR 2, MMAT…). Porte
 * l'appel LLM ancré sur les sources et le GARDE-FOU (SPECS §2.4, §17) : une réponse non
 * adossée à une citation exacte (ou à une absence vérifiée) est rétrogradée en réponse
 * « incertaine » et marquée pour revue humaine. Chaque référentiel ne fournit que ses
 * critères, son échelle de réponse et une courte consigne.
 */
abstract class AbstractCriteriaAnalyzer implements AnalyzerInterface
{
    use QuoteAnchoring;

    private const ANCHORED_TYPES = ['explicit_quote', 'absence_verified'];
    private const VALID_CONFIDENCE = ['high', 'medium', 'low'];

    private ?SettingsService $settings = null;

    public function __construct(protected readonly LlmPort $llm, protected readonly string $model)
    {
    }

    /** Injection par setter : le modèle effectif est configurable à chaud (réglages admin). */
    #[Required]
    public function setSettings(SettingsService $settings): void
    {
        $this->settings = $settings;
    }

    /** Modèle effectif : réglage admin (analys.default_model) sinon valeur d'environnement. */
    protected function model(): string
    {
        return $this->settings?->analysisModel() ?? $this->model;
    }

    /** @return list<array{id: string, dimension: string, question: string}> */
    abstract protected function criteria(): array;

    /** @return list<string> réponses autorisées (la 1re « incertaine » sert de repli) */
    abstract protected function validAnswers(): array;

    abstract protected function unclearAnswer(): string;

    /** Consigne d'introduction propre au référentiel (1-3 phrases). */
    abstract protected function promptIntro(): string;

    public function analyze(string $fulltext, array $meta): array
    {
        $criteria = $this->criteria();
        $excerpt = mb_substr('' !== trim($fulltext) ? $fulltext : (string) ($meta['abstract'] ?? ''), 0, 16000);
        $answers = implode(', ', $this->validAnswers());
        $list = implode("\n", array_map(
            static fn (array $c): string => \sprintf('- [%s] %s', $c['id'], $c['question']),
            $criteria,
        ));

        $prompt = <<<PROMPT
            {$this->promptIntro()}
            RÈGLES STRICTES :
            - Réponds UNIQUEMENT d'après le texte fourni. N'invente aucune information.
            - answer ∈ {{$answers}}.
            - Fournis une citation EXACTE du texte (champ "quote") qui ancre ta réponse.
            - Si aucune citation n'ancre la réponse, mets answer="{$this->unclearAnswer()}" et evidence_type="absence_from_extracted_text_only".
            - evidence_type ∈ {explicit_quote, inference, absence_verified, absence_from_extracted_text_only}.
            - confidence ∈ {high, medium, low}. Une "inference" ne peut pas avoir confidence "high".
            - "analysis" : une phrase de justification en français.

            Critères :
            {$list}

            Texte de l'étude :
            {$excerpt}

            Réponds STRICTEMENT en JSON :
            {"answers":[{"criterion_id":"...","answer":"...","quote":"...","evidence_type":"...","confidence":"...","analysis":"..."}]}
            PROMPT;

        $model = $this->model();
        $out = $this->llm->generateJson($prompt, $model);
        $byId = $this->indexAnswers($out['answers'] ?? []);

        $results = [];
        $downgraded = 0;
        $answered = 0;
        foreach ($criteria as $c) {
            $result = $this->applyGuardrail($c, $byId[$c['id']] ?? [], $excerpt);
            $results[] = $result;
            if ($result['requires_human_review']) {
                ++$downgraded;
            }
            if (!$this->isInconclusive($result['answer'])) {
                ++$answered;
            }
        }

        $total = \count($criteria);
        $coverage = $total > 0 ? round($answered / $total, 2) : 0.0;

        return [
            'criteria' => $results,
            'overall' => [
                'framework_id' => $this->frameworkId(),
                'model' => $model,
                'coverage' => $coverage,
                'answered' => $answered,
                'downgraded' => $downgraded,
                'human_review' => $coverage < 0.6 || $downgraded > (int) ceil($total * 0.3),
            ],
        ];
    }

    /**
     * @param array{id: string, dimension: string, question: string} $criterion
     * @param array<string, mixed>                                    $raw
     *
     * @return array<string, mixed>
     */
    private function applyGuardrail(array $criterion, array $raw, string $text): array
    {
        $unclear = $this->unclearAnswer();
        $answer = \in_array($raw['answer'] ?? null, $this->validAnswers(), true) ? (string) $raw['answer'] : $unclear;
        $evidenceType = \is_string($raw['evidence_type'] ?? null) ? (string) $raw['evidence_type'] : 'absence_from_extracted_text_only';
        $quote = \is_string($raw['quote'] ?? null) ? trim((string) $raw['quote']) : '';
        $confidence = \in_array($raw['confidence'] ?? null, self::VALID_CONFIDENCE, true) ? (string) $raw['confidence'] : 'low';
        $analysis = \is_string($raw['analysis'] ?? null) ? trim((string) $raw['analysis']) : null;

        $requiresReview = false;
        if (!$this->isInconclusive($answer)) {
            $anchored = \in_array($evidenceType, self::ANCHORED_TYPES, true)
                && ('absence_verified' === $evidenceType || '' !== $quote);

            // VÉRIFICATION D'ANCRAGE : une citation explicite doit exister LITTÉRALEMENT
            // dans le texte fourni (on ne fait pas confiance à l'auto-déclaration du LLM).
            // Sinon la « citation » est une hallucination → non ancrée.
            if ($anchored && 'explicit_quote' === $evidenceType && !$this->quoteInText($quote, $text)) {
                $anchored = false;
                $evidenceType = 'unverified_quote';
            }

            if (!$anchored) {
                $answer = $unclear;
                $requiresReview = true;
            }
        }

        if ('inference' === $evidenceType && 'high' === $confidence) {
            $confidence = 'medium';
        }

        return [
            'criterion_id' => $criterion['id'],
            'dimension' => $criterion['dimension'],
            'question' => $criterion['question'],
            'answer' => $answer,
            'evidence_type' => $evidenceType,
            'confidence' => $confidence,
            'quote' => $quote,
            'analysis' => $analysis,
            'requires_human_review' => $requiresReview,
        ];
    }

    /** Une réponse « incertaine » ou « non applicable » ne compte pas comme conclusive. */
    private function isInconclusive(string $answer): bool
    {
        return \in_array($answer, [$this->unclearAnswer(), 'not_applicable'], true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexAnswers(mixed $answers): array
    {
        $indexed = [];
        if (\is_array($answers)) {
            foreach ($answers as $a) {
                if (\is_array($a) && \is_string($a['criterion_id'] ?? null)) {
                    $indexed[$a['criterion_id']] = $a;
                }
            }
        }

        return $indexed;
    }
}
