<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Sdk\LlmPort;

/**
 * Socle commun aux analyseurs « par critères » (AXIS, RoB 2, AMSTAR 2, MMAT…). Porte
 * l'appel LLM ancré sur les sources et le GARDE-FOU (SPECS §2.4, §17) : une réponse non
 * adossée à une citation exacte (ou à une absence vérifiée) est rétrogradée en réponse
 * « incertaine » et marquée pour revue humaine. Chaque référentiel ne fournit que ses
 * critères, son échelle de réponse et une courte consigne.
 */
abstract class AbstractCriteriaAnalyzer implements AnalyzerInterface
{
    private const ANCHORED_TYPES = ['explicit_quote', 'absence_verified'];
    private const VALID_CONFIDENCE = ['high', 'medium', 'low'];

    public function __construct(protected readonly LlmPort $llm, protected readonly string $model)
    {
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

        $out = $this->llm->generateJson($prompt, $this->model);
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
                'model' => $this->model,
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
     * La citation apparaît-elle RÉELLEMENT dans le texte ? Comparaison normalisée
     * (minuscules, sans accents ni ponctuation, espaces compactés) pour tolérer la
     * mise en forme, avec repli sur les 8 premiers mots (le LLM tronque souvent la fin).
     */
    private function quoteInText(string $quote, string $text): bool
    {
        $nt = $this->normalizeForMatch($text);
        $words = explode(' ', $this->normalizeForMatch($quote));
        $n = \count($words);
        if ($n < 5) {
            return false; // trop court pour être une citation vérifiable
        }

        // Ancrage = au moins une SÉQUENCE contiguë de la citation existe dans le texte.
        // Tolère la troncature/reformulation aux extrémités, mais exige un vrai passage
        // copié (≥ 6 mots consécutifs, ou la citation entière si plus courte).
        $window = 6;
        if ($n <= $window) {
            return str_contains($nt, implode(' ', $words));
        }
        for ($i = 0; $i + $window <= $n; ++$i) {
            if (str_contains($nt, implode(' ', \array_slice($words, $i, $window)))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeForMatch(string $s): string
    {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;         // retire les diacritiques
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;      // ponctuation → espace
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($s);
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
