<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Framework\Axis\AxisFramework;
use Analyses\Sdk\LlmPort;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exécuteur AXIS : interroge le LLM sur les 20 critères, STRICTEMENT ancré sur le texte.
 * Garde-fou (SPECS §2.4, §17) : une réponse non adossée à une citation exacte (ou à une
 * absence vérifiée) est rétrogradée en « unclear » et marquée pour revue humaine.
 */
final class AxisAnalyzer implements AnalyzerInterface
{
    private const VALID_ANSWERS = ['yes', 'partial', 'no', 'unclear', 'not_applicable'];
    private const ANCHORED_TYPES = ['explicit_quote', 'absence_verified'];
    private const VALID_CONFIDENCE = ['high', 'medium', 'low'];

    public function __construct(
        private readonly LlmPort $llm,
        private readonly AxisFramework $axis = new AxisFramework(),
        #[Autowire(env: 'ANALYS_MODEL')]
        private readonly string $model = 'glm-5.2:cloud',
    ) {
    }

    public function frameworkId(): string
    {
        return 'axis';
    }

    public function analyze(string $fulltext, array $meta): array
    {
        $criteria = $this->axis->criteria();
        $excerpt = mb_substr('' !== trim($fulltext) ? $fulltext : (string) ($meta['abstract'] ?? ''), 0, 16000);

        $list = implode("\n", array_map(
            static fn (array $c): string => \sprintf('- [%s] %s', $c['id'], $c['question']),
            $criteria,
        ));

        $prompt = <<<PROMPT
            Tu es un évaluateur méthodologique. Évalue l'étude selon les critères AXIS (études transversales).
            RÈGLES STRICTES :
            - Réponds UNIQUEMENT d'après le texte fourni. N'invente aucune information.
            - answer ∈ {yes, partial, no, unclear, not_applicable}.
            - Fournis une citation EXACTE du texte (champ "quote") qui ancre ta réponse.
            - Si aucune citation n'ancre la réponse, mets answer="unclear" et evidence_type="absence_from_extracted_text_only".
            - evidence_type ∈ {explicit_quote, inference, absence_verified, absence_from_extracted_text_only}.
            - confidence ∈ {high, medium, low}. Une "inference" ne peut pas avoir confidence "high".
            - "analysis" : une phrase de justification en français.

            Critères :
            {$list}

            Texte de l'étude :
            {$excerpt}

            Réponds STRICTEMENT en JSON :
            {"answers":[{"criterion_id":"axis.qXX","answer":"...","quote":"...","evidence_type":"...","confidence":"...","analysis":"..."}]}
            PROMPT;

        $out = $this->llm->generateJson($prompt, $this->model);
        $byId = $this->indexAnswers($out['answers'] ?? []);

        $results = [];
        $downgraded = 0;
        $answered = 0;
        foreach ($criteria as $c) {
            $raw = $byId[$c['id']] ?? [];
            $result = $this->applyGuardrail($c, $raw);
            $results[] = $result;
            if ($result['requires_human_review']) {
                ++$downgraded;
            }
            if (!\in_array($result['answer'], ['unclear', 'not_applicable'], true)) {
                ++$answered;
            }
        }

        $total = \count($criteria);
        $coverage = $total > 0 ? round($answered / $total, 2) : 0.0;
        $humanReview = $coverage < 0.6 || $downgraded > (int) ceil($total * 0.3);

        return [
            'criteria' => $results,
            'overall' => [
                'framework_id' => 'axis',
                'model' => $this->model,
                'coverage' => $coverage,
                'answered' => $answered,
                'downgraded' => $downgraded,
                'human_review' => $humanReview,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $criterion
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function applyGuardrail(array $criterion, array $raw): array
    {
        $answer = \in_array($raw['answer'] ?? null, self::VALID_ANSWERS, true) ? (string) $raw['answer'] : 'unclear';
        $evidenceType = \is_string($raw['evidence_type'] ?? null) ? (string) $raw['evidence_type'] : 'absence_from_extracted_text_only';
        $quote = \is_string($raw['quote'] ?? null) ? trim((string) $raw['quote']) : '';
        $confidence = \in_array($raw['confidence'] ?? null, self::VALID_CONFIDENCE, true) ? (string) $raw['confidence'] : 'low';
        $analysis = \is_string($raw['analysis'] ?? null) ? trim((string) $raw['analysis']) : null;

        $requiresReview = false;

        // Garde-fou d'ancrage : une réponse affirmative/négative doit être adossée à une
        // citation exacte, ou à une absence VÉRIFIÉE. Sinon → unclear + revue humaine.
        if (!\in_array($answer, ['unclear', 'not_applicable'], true)) {
            $anchored = \in_array($evidenceType, self::ANCHORED_TYPES, true)
                && ('absence_verified' === $evidenceType || '' !== $quote);
            if (!$anchored) {
                $answer = 'unclear';
                $requiresReview = true;
            }
        }

        // Une inférence ne peut pas être « high » (SPECS §17 règle 2).
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

    /**
     * @param mixed $answers
     *
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
