<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Suivi de la moisson (ROLE_ADMIN) : état de chaque worker/rubrique (en attente,
 * en cours, terminé, en erreur), volume + durée, et remontée explicite des
 * erreurs (dépassement des limites OpenAlex). Affiche aussi la transparence
 * OpenAlex : on n'utilise PAS de clé API (polite pool via mailto) ; le crédit
 * accordé et le coût dépensé du jour proviennent des en-têtes x-ratelimit-*-usd.
 */
final class AdminHarvestController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingsService $settings,
        private readonly \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'OPENALEX_API_KEY')]
        private readonly string $openalexApiKey = '',
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'ML_EMBED_URL')]
        private readonly string $mlEmbedUrl = '',
    ) {
    }

    /**
     * Nettoyage du journal des moissons (lignes ingestion_job des rubriques) :
     *  - duplicates : ne garde que la plus récente par rubrique (préserve le curseur de reprise) ;
     *  - finished   : supprime les moissons non actives (terminées, en erreur, ou « en cours » orphelines >10 min) ;
     *  - all        : supprime toutes les lignes de moisson par rubrique.
     * N'affecte JAMAIS le corpus (publications, embeddings, placements).
     */
    #[Route('/api/admin/harvest/cleanup', name: 'admin_harvest_cleanup', methods: ['POST'])]
    public function cleanup(\Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        $mode = (string) (json_decode($request->getContent() ?: '[]', true)['mode'] ?? '');
        $conn = $this->em->getConnection();

        $sql = match ($mode) {
            'duplicates' => "DELETE FROM ingestion_job
                WHERE query->>'rubric' IS NOT NULL
                  AND id NOT IN (SELECT max(id) FROM ingestion_job WHERE query->>'rubric' IS NOT NULL GROUP BY query->>'rubric')",
            'finished' => "DELETE FROM ingestion_job
                WHERE query->>'rubric' IS NOT NULL
                  AND (status <> 'running' OR started_at < now() - interval '10 minutes')",
            'all' => "DELETE FROM ingestion_job WHERE query->>'rubric' IS NOT NULL",
            default => null,
        };
        if (null === $sql) {
            return new JsonResponse(['error' => 'Mode inconnu (duplicates|finished|all).'], 422);
        }

        $deleted = (int) $conn->executeStatement($sql);

        return new JsonResponse([
            'deleted' => $deleted,
            'message' => \sprintf('%d ligne(s) de moisson supprimée(s).', $deleted),
        ]);
    }

    #[Route('/api/admin/harvest/status', name: 'admin_harvest_status', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $conn = $this->em->getConnection();

        // --- UNE ligne par rubrique : la moisson la plus récente (DISTINCT ON).
        // Relancer une rubrique remplace donc l'affichage au lieu d'empiler des
        // doublons ; l'historique complet reste en base (et dans le journal d'audit).
        $rows = $conn->executeQuery(
            "SELECT * FROM (
                SELECT DISTINCT ON (j.query->>'rubric')
                       j.id, j.query->>'rubric' AS rubric, tn.id AS node_id, tn.label AS label,
                       j.started_at, j.finished_at, j.processed, j.created, j.errors,
                       j.status, j.log,
                       EXTRACT(EPOCH FROM (COALESCE(j.finished_at, now()) - j.started_at)) AS duration_s,
                       (j.status = 'running' AND j.started_at < now() - interval '10 minutes') AS stale
                FROM ingestion_job j
                LEFT JOIN tree_node tn ON tn.slug = (j.query->>'rubric')
                WHERE j.query->>'rubric' IS NOT NULL
                ORDER BY j.query->>'rubric', j.started_at DESC
             ) latest
             ORDER BY started_at DESC
             LIMIT 60"
        )->fetchAllAssociative();

        $jobs = array_map(static function (array $r): array {
            $log = $r['log'] ?? null;
            // Dépassement des limites OpenAlex : plafond interne, réponse 429,
            // ou filtre payant (« plan upgrade required »).
            $isQuota = null !== $log && preg_match('/quota|rate ?limit|429|too many|plan upgrade|plafond/i', (string) $log) === 1;

            return [
                'id' => (int) $r['id'],
                'rubric' => $r['rubric'],
                'nodeId' => null !== $r['node_id'] ? (int) $r['node_id'] : null,
                'label' => $r['label'] ?? $r['rubric'],
                'startedAt' => $r['started_at'],
                'finishedAt' => $r['finished_at'],
                'durationSeconds' => null !== $r['duration_s'] ? (int) round((float) $r['duration_s']) : null,
                'processed' => (int) $r['processed'],
                'created' => (int) $r['created'],
                'errors' => (int) $r['errors'],
                'status' => $r['status'],
                'log' => $log,
                'rateLimited' => $isQuota,
                'stale' => (bool) $r['stale'],
            ];
        }, $rows);

        // --- File d'attente Messenger (moissons pas encore prises par un worker) ---
        // On récupère aussi les nodeId en attente (sérialisés « i:<id>; ») pour
        // marquer précisément quelles rubriques sont en file.
        $queued = 0;
        $queuedNodeIds = [];
        try {
            $bodies = $conn->executeQuery(
                "SELECT body FROM messenger_messages
                 WHERE delivered_at IS NULL AND body LIKE '%HarvestRubric%'"
            )->fetchFirstColumn();
            $queued = \count($bodies);
            foreach ($bodies as $body) {
                if (preg_match('/i:(\d+);/', (string) $body, $m)) {
                    $queuedNodeIds[(int) $m[1]] = true;
                }
            }
        } catch (\Throwable) {
            // Table absente (autre transport) : on laisse 0.
        }
        foreach ($jobs as &$jb) {
            $jb['queued'] = null !== $jb['nodeId'] && isset($queuedNodeIds[$jb['nodeId']]);
        }
        unset($jb);

        // --- Total disponible chez OpenAlex par rubrique (meta.count mémorisé) ---
        $totals = [];
        foreach ($conn->executeQuery("SELECT name, value FROM setting WHERE name LIKE 'openalex.total.%'")->fetchAllAssociative() as $row) {
            $totals[substr((string) $row['name'], \strlen('openalex.total.'))] = (int) $row['value'];
        }
        // Cumul moissonné par rubrique (toutes exécutions) → progression réelle.
        $cumulative = [];
        foreach ($conn->executeQuery("SELECT query->>'rubric' AS r, COALESCE(SUM(processed),0) AS s FROM ingestion_job WHERE query->>'rubric' IS NOT NULL GROUP BY query->>'rubric'")->fetchAllAssociative() as $row) {
            $cumulative[(string) $row['r']] = (int) $row['s'];
        }
        foreach ($jobs as &$job) {
            $job['available'] = $job['rubric'] !== null && isset($totals[$job['rubric']]) ? $totals[$job['rubric']] : null;
            $job['cumulativeProcessed'] = $cumulative[$job['rubric']] ?? $job['processed'];
        }
        unset($job);

        // --- Limites & crédits OpenAlex (valeurs RÉELLES lues sur les en-têtes) ---
        $today = date('Y-m-d');
        $s = $this->readSettings([
            'openalex.count.'.$today,
            'openalex.rl.limit', 'openalex.rl.remaining', 'openalex.rl.reset', 'openalex.rl.credits_used',
            'openalex.credit.limit_usd', 'openalex.credit.remaining_usd', 'openalex.credit.cost_usd', 'openalex.credit.updated_at',
        ]);
        $num = static fn (string $k): ?float => isset($s[$k]) && is_numeric($s[$k]) ? (float) $s[$k] : null;

        $used = (int) ($s['openalex.count.'.$today] ?? 0);
        $perDay = $this->settings->openalexPerDay();
        $apiLimit = $num('openalex.rl.limit');       // limite quotidienne réelle d'OpenAlex (nb requêtes)
        $apiRemaining = $num('openalex.rl.remaining');
        $limitUsd = $num('openalex.credit.limit_usd');
        $remainingUsd = $num('openalex.credit.remaining_usd');

        return new JsonResponse([
            'jobs' => $jobs,
            'queued' => $queued,
            'embeddingModel' => $_SERVER['EMBEDDING_MODEL'] ?? $_ENV['EMBEDDING_MODEL'] ?? 'sentence-transformers (Marvin)',
            // Stratégie effective : sert à calculer la progression (cumul / cible).
            'harvest' => [
                'maxPerRun' => $this->settings->harvestMaxPerRun(),
                'capPerRubric' => $this->settings->harvestCapPerRubric(),
                'sort' => $this->settings->harvestSort(),
                'recentYears' => $this->settings->harvestRecentYears(),
            ],
            'openalex' => [
                'date' => $today,
                'usesApiKey' => '' !== $this->openalexApiKey,
                // Garde-fou interne (cadence configurable en back-office).
                'used' => $used,
                'perDay' => $perDay,
                'perMinute' => $this->settings->openalexPerMinute(),
                'exhausted' => $used >= $perDay,
                // Valeurs RÉELLES annoncées par OpenAlex (en-têtes X-RateLimit-*).
                'apiDailyLimit' => null !== $apiLimit ? (int) $apiLimit : null,
                'apiDailyRemaining' => null !== $apiRemaining ? (int) $apiRemaining : null,
                'creditLimitUsd' => $limitUsd,
                'creditRemainingUsd' => $remainingUsd,
                'creditSpentUsd' => (null !== $limitUsd && null !== $remainingUsd) ? round($limitUsd - $remainingUsd, 4) : null,
                'creditCostUsd' => $num('openalex.credit.cost_usd'),
                'creditUpdatedAt' => $s['openalex.credit.updated_at'] ?? null,
            ],
            // Charge machines : Thor (cette instance) + Marvin (service ml).
            'system' => $this->systemStats(),
        ]);
    }

    /**
     * @return array<string,array<string,mixed>|null>
     */
    private function systemStats(): array
    {
        return ['thor' => $this->localStats(), 'marvin' => $this->marvinStats()];
    }

    /** Charge CPU (loadavg) + mémoire de l'hôte local (Thor), lues dans /proc. */
    private function localStats(): array
    {
        $load = \function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
        $cpus = max(1, \count(glob('/sys/devices/system/cpu/cpu[0-9]*') ?: []) ?: 1);
        [$total, $avail] = $this->meminfo();

        return [
            'load1' => round((float) $load[0], 2),
            'cpus' => $cpus,
            'loadPct' => (int) min(100, round((float) $load[0] / $cpus * 100)),
            'memPct' => $total > 0 ? (int) round(($total - $avail) / $total * 100) : null,
            'memTotalGb' => $total > 0 ? round($total / 1048576, 1) : null,
        ];
    }

    /** Charge de Marvin via le service ml (/stats). Null si injoignable. */
    private function marvinStats(): ?array
    {
        $base = preg_replace('#/embed.*$#', '', $this->mlEmbedUrl);
        if (null === $base || '' === $base) {
            return null;
        }
        try {
            $d = $this->httpClient->request('GET', rtrim($base, '/').'/stats', ['timeout' => 3])->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        return [
            'load1' => $d['load1'] ?? null,
            'cpus' => $d['cpus'] ?? null,
            'loadPct' => $d['loadPct'] ?? null,
            'memPct' => $d['memPct'] ?? null,
            'memTotalGb' => isset($d['memTotalKb']) ? round(((int) $d['memTotalKb']) / 1048576, 1) : null,
        ];
    }

    /** @return array{0:int,1:int} [MemTotal, MemAvailable] en kB (hôte). */
    private function meminfo(): array
    {
        $total = $avail = 0;
        foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
            if (str_starts_with($line, 'MemTotal:')) {
                $total = (int) preg_replace('/\D/', '', $line);
            } elseif (str_starts_with($line, 'MemAvailable:')) {
                $avail = (int) preg_replace('/\D/', '', $line);
            }
        }

        return [$total, $avail];
    }

    /**
     * @param list<string> $names
     *
     * @return array<string,string>
     */
    private function readSettings(array $names): array
    {
        $rows = $this->em->getConnection()->executeQuery(
            'SELECT name, value FROM setting WHERE name IN (:names)',
            ['names' => $names],
            ['names' => \Doctrine\DBAL\ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['name']] = (string) $row['value'];
        }

        return $out;
    }
}
