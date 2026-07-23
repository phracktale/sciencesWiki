<?php

declare(strict_types=1);

namespace App\Ai\Llm;

use Doctrine\DBAL\Connection;

/**
 * Compteur de consommation LLM (tokens) agrégé par jour et par modèle.
 *
 * Alimenté à chaque appel LLM non streamé (cf. OpenAiCompatibleLlmClient::complete).
 * L'incrément est un UPSERT atomique (ON CONFLICT) — sûr en concurrence entre workers.
 * Le comptage ne doit JAMAIS faire échouer une génération : toute erreur est avalée.
 */
final class LlmUsageMeter
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function record(string $model, ?int $promptTokens, ?int $completionTokens): void
    {
        $model = '' !== trim($model) ? mb_substr(trim($model), 0, 160) : 'inconnu';
        $p = max(0, (int) $promptTokens);
        $c = max(0, (int) $completionTokens);

        try {
            $this->conn->executeStatement(
                'INSERT INTO llm_usage_daily (day, model, prompt_tokens, completion_tokens, calls)
                 VALUES (CURRENT_DATE, :m, :p, :c, 1)
                 ON CONFLICT (day, model) DO UPDATE SET
                     prompt_tokens = llm_usage_daily.prompt_tokens + EXCLUDED.prompt_tokens,
                     completion_tokens = llm_usage_daily.completion_tokens + EXCLUDED.completion_tokens,
                     calls = llm_usage_daily.calls + 1',
                ['m' => $model, 'p' => $p, 'c' => $c],
            );
        } catch (\Throwable) {
            // Monitoring best-effort : ne jamais perturber la génération.
        }
    }

    /**
     * Consommation du jour : total + ventilation par modèle (le plus gros d'abord).
     *
     * @return array{totalPrompt:int, totalCompletion:int, totalTokens:int, totalCalls:int, models:list<array{model:string, prompt:int, completion:int, calls:int}>}
     */
    public function today(): array
    {
        try {
            $rows = $this->conn->fetchAllAssociative(
                'SELECT model, prompt_tokens, completion_tokens, calls
                 FROM llm_usage_daily
                 WHERE day = CURRENT_DATE
                 ORDER BY (prompt_tokens + completion_tokens) DESC',
            );
        } catch (\Throwable) {
            $rows = [];
        }

        $tp = $tc = $tk = 0;
        $models = [];
        foreach ($rows as $r) {
            $p = (int) $r['prompt_tokens'];
            $co = (int) $r['completion_tokens'];
            $ca = (int) $r['calls'];
            $tp += $p;
            $tc += $co;
            $tk += $ca;
            $models[] = ['model' => (string) $r['model'], 'prompt' => $p, 'completion' => $co, 'calls' => $ca];
        }

        return [
            'totalPrompt' => $tp,
            'totalCompletion' => $tc,
            'totalTokens' => $tp + $tc,
            'totalCalls' => $tk,
            'models' => $models,
        ];
    }
}
