<?php

declare(strict_types=1);

namespace App\Ai\Llm;

/**
 * LLM factice et déterministe pour le dev et les tests : renvoie un brouillon
 * marqué, sans appeler de service externe. Permet de faire tourner le pipeline
 * de rédaction (et de booter l'app) sans LLM disponible.
 */
final class StubLlmClient implements LlmClient
{
    public function complete(array $messages, array $options = []): LlmCompletion
    {
        $system = '';
        $lastUser = '';
        foreach ($messages as $message) {
            if ('system' === $message->role) {
                $system = $message->content;
            } elseif ('user' === $message->role) {
                $lastUser = $message->content;
            }
        }

        // Extraction d'assertions (cf. spec controverses §5) : JSON déterministe,
        // dont la quote reprend le TITRE pour passer le garde-fou anti-hallucination.
        if (str_contains($system, 'RELATION CAUSALE')) {
            return new LlmCompletion($this->stubClaims($lastUser), 'stub', null, null);
        }

        // Évaluation AXIS (cf. spec axis §5) : grille déterministe d'étude transversale.
        if (str_contains($system, 'AXIS')) {
            return new LlmCompletion($this->stubAxis($lastUser), 'stub', null, null);
        }

        $content = "[brouillon généré par le LLM factice — non destiné à la publication]\n\n"
            .mb_substr(trim($lastUser), 0, 280);

        return new LlmCompletion($content, 'stub', null, null);
    }

    /** Une assertion factice ancrée sur le titre de l'article (déterministe). */
    private function stubClaims(string $userPrompt): string
    {
        $title = 'résultat rapporté';
        if (preg_match('/TITRE\s*:\s*(.+)/u', $userPrompt, $m)) {
            $title = trim($m[1]);
        }

        return json_encode([
            'claims' => [[
                'exposure' => 'facteur étudié',
                'outcome' => 'effet mesuré',
                'direction' => 'positive',
                'method' => 'observational',
                'confidence' => 'moderate',
                'population' => null,
                'sample_size' => null,
                'effect_size' => null,
                'stated_limitations' => null,
                'future_work' => [],
                'quote' => $title,
            ]],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Évaluation AXIS factice et déterministe : étude transversale, 20 items
     * répondus favorablement (les items inversés q13/q19 à « no »). Aucune réponse
     * défavorable → pas de citation à ancrer (le pipeline tourne sans LLM réel).
     */
    private function stubAxis(string $userPrompt): string
    {
        $items = [];
        for ($i = 1; $i <= 20; ++$i) {
            $key = 'q'.$i;
            // q13 (biais de non-réponse) et q19 (conflits d'intérêts) : « no » = favorable.
            $answer = (13 === $i || 19 === $i) ? 'no' : 'yes';
            $items[$key] = ['answer' => $answer, 'quote' => null];
        }

        return json_encode([
            'study_design' => 'cross-sectional',
            'applicable' => true,
            'items' => $items,
            'summary' => '[évaluation AXIS factice] Étude transversale ; méthodologie jugée solide par le LLM de test.',
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    public function stream(array $messages, array $options = []): iterable
    {
        // Émet le contenu factice mot à mot (simule le flux pour le front).
        foreach (explode(' ', $this->complete($messages, $options)->content) as $i => $word) {
            yield (0 === $i ? '' : ' ').$word;
        }
    }

    public function model(): string
    {
        return 'stub';
    }
}
