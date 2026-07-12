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
        private readonly \App\Harvester\Connector\OpenAlex\OpenAlexConnector $openalex,
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
    /**
     * Rafraîchit à la demande les en-têtes de crédit OpenAlex (1 requête authentifiée),
     * indépendamment d'une moisson : permet de consulter le solde gratuit/prépayé à tout
     * moment même si la moisson est à l'arrêt.
     */
    #[Route('/api/admin/harvest/refresh-credit', name: 'admin_harvest_refresh_credit', methods: ['POST'])]
    public function refreshCredit(): JsonResponse
    {
        try {
            $this->openalex->pingCredit();

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    /**
     * Recalcule les totaux de progression (moissonnés / depuis la borne / cible OpenAlex)
     * et les stocke en réglages pour un affichage instantané. Un seul seq-scan de la table
     * `publication` donne les deux compteurs EXACTS ; la cible réaliste vient d'une requête
     * OpenAlex (articles+reviews en/fr depuis la borne). Bouton manuel : quelques secondes.
     */
    #[Route('/api/admin/harvest/recompute-stats', name: 'admin_harvest_recompute_stats', methods: ['POST'])]
    public function recomputeStats(): JsonResponse
    {
        $conn = $this->em->getConnection();
        try {
            $from = (int) ($conn->fetchOne("SELECT value FROM setting WHERE name = 'harvest.covered.from'") ?: 2015);

            // Un seul parcours de table : total ET intégrés depuis la borne, tous deux exacts.
            $conn->executeStatement("SET statement_timeout = '180s'");
            $counts = $conn->fetchAssociative(
                'SELECT count(*) AS total, count(*) FILTER (WHERE publication_date >= :d) AS since
                 FROM publication',
                ['d' => \sprintf('%04d-01-01', $from)]
            ) ?: ['total' => 0, 'since' => 0];
            $total = (int) $counts['total'];
            $since = (int) $counts['since'];

            // Cible réaliste : articles + reviews en anglais/français depuis la borne.
            $universe = $this->openalex->countWorks(\sprintf(
                'type:article|review,from_publication_date:%04d-01-01,language:en|fr',
                $from
            ));

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $upsert = 'INSERT INTO setting(name, value) VALUES(:n, :v) ON CONFLICT(name) DO UPDATE SET value = EXCLUDED.value';
            foreach ([
                'harvest.integrated.total' => (string) $total,
                'harvest.integrated.since2015' => (string) $since,
                'harvest.universe.articles' => (string) ($universe ?? 0),
                'harvest.covered.to' => gmdate('Y'),
                'harvest.stats.updated_at' => $now,
            ] as $n => $v) {
                $conn->executeStatement($upsert, ['n' => $n, 'v' => $v]);
            }

            return new JsonResponse(['ok' => true, 'integrated' => $total, 'since' => $since, 'universe' => $universe]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

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
            'openalex.credit.prepaid_remaining_usd',
            'harvest.integrated.total', 'harvest.integrated.since2015',
            'harvest.covered.from', 'harvest.covered.to',
            'harvest.universe.articles', 'harvest.stats.updated_at',
        ]);
        $num = static fn (string $k): ?float => isset($s[$k]) && is_numeric($s[$k]) ? (float) $s[$k] : null;

        $used = (int) ($s['openalex.count.'.$today] ?? 0);
        $perDay = $this->settings->openalexPerDay();
        // Les en-têtes X-RateLimit-* d'OpenAlex portent sur le jour UTC courant (reset
        // à minuit UTC). Une valeur enregistrée un jour UTC ANTÉRIEUR est périmée : on
        // ne s'y fie plus (sinon un « remaining=0 » de la veille laisse le panneau
        // « limite atteinte » collé jusqu'à ce qu'une requête le rafraîchisse). Dans ce
        // cas, repli sur le compteur interne openalex.count.<jour> (remis à zéro par date).
        $creditAt = $s['openalex.credit.updated_at'] ?? null;
        $rlDayUtc = null !== $creditAt
            ? (new \DateTimeImmutable((string) $creditAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d')
            : null;
        $rlFresh = null !== $rlDayUtc && $rlDayUtc === gmdate('Y-m-d');
        // Toutes les valeurs OpenAlex (requêtes ET crédit USD) ne sont fiables que si les
        // en-têtes datent du jour UTC courant. Périmées → null (le front affiche « — »)
        // plutôt qu'un crédit obsolète : la page reflète la VÉRITÉ du moment, pas un reliquat.
        $apiLimit = $rlFresh ? $num('openalex.rl.limit') : null;       // limite quotidienne réelle d'OpenAlex (nb requêtes), si datée du jour
        $apiRemaining = $rlFresh ? $num('openalex.rl.remaining') : null;
        $limitUsd = $rlFresh ? $num('openalex.credit.limit_usd') : null;
        $remainingUsd = $rlFresh ? $num('openalex.credit.remaining_usd') : null;
        // Solde prépayé (balance persistante, non gaté par le jour).
        $prepaidUsd = $num('openalex.credit.prepaid_remaining_usd');
        // BLOQUÉ (bandeau rouge) UNIQUEMENT si les requêtes du jour sont épuisées
        // (10000/10000, en-têtes frais) ET qu'il n'y a AUCUN prépayé pour prendre le relais.
        // Tant qu'il reste du prépayé, la moisson continue → pas de bandeau.
        $blocked = $rlFresh && null !== $apiRemaining && (int) $apiRemaining <= 0
            && (null === $prepaidUsd || $prepaidUsd <= 0.00005);

        // --- Couverture GLOBALE de la moisson par rubrique (où on en est / ce qui reste) ---
        $cov = ['ok' => 0, 'failed' => 0, 'partial' => 0, 'running' => 0, 'pending' => 0, 'rateLimited' => 0, 'total' => 0];
        foreach ($conn->executeQuery(
            "SELECT status, count(*) AS n,
                    count(*) FILTER (WHERE log ~* '429|rate ?limit|limite openalex') AS rl
             FROM ingestion_job WHERE query->>'rubric' IS NOT NULL GROUP BY status"
        )->fetchAllAssociative() as $row) {
            $st = (string) $row['status'];
            $cov[$st] = ($cov[$st] ?? 0) + (int) $row['n'];
            $cov['total'] += (int) $row['n'];
            if ('failed' === $st) {
                $cov['rateLimited'] += (int) $row['rl'];
            }
        }

        return new JsonResponse([
            'jobs' => $jobs,
            'queued' => $queued,
            'coverage' => $cov,
            'embeddingModel' => $_SERVER['EMBEDDING_MODEL'] ?? $_ENV['EMBEDDING_MODEL'] ?? 'sentence-transformers (Marvin)',
            // Stratégie effective : sert à calculer la progression (cumul / cible).
            'harvest' => [
                'maxPerRun' => $this->settings->harvestMaxPerRun(),
                'capPerRubric' => $this->settings->harvestCapPerRubric(),
                'sort' => $this->settings->harvestSort(),
                'recentYears' => $this->settings->harvestRecentYears(),
                // Synthèse de progression (totaux coûteux pré-calculés et stockés en
                // réglages ; bouton « recalculer » pour les rafraîchir à la demande).
                'integrated' => (int) ($num('harvest.integrated.total') ?? 0),
                'integratedSince2015' => (int) ($num('harvest.integrated.since2015') ?? 0),
                'coveredFrom' => (int) ($num('harvest.covered.from') ?? 0),
                'coveredTo' => (int) ($num('harvest.covered.to') ?? 0),
                // Cible réaliste = articles+reviews en/fr publiés depuis la borne basse
                // (ce que la moisson vise vraiment, pas le volume brut tous types).
                'universeArticles' => (int) ($num('harvest.universe.articles') ?? 0),
                'statsUpdatedAt' => $s['harvest.stats.updated_at'] ?? null,
            ],
            'openalex' => [
                'date' => $today,
                'usesApiKey' => '' !== ($this->settings->openalexApiKey() ?: $this->openalexApiKey),
                // Stats fondées sur les requêtes RÉELLEMENT enregistrées par OpenAlex
                // (en-têtes X-RateLimit-*) quand disponibles ; repli sur le compteur interne.
                'used' => (null !== $apiLimit && null !== $apiRemaining) ? max(0, (int) $apiLimit - (int) $apiRemaining) : $used,
                'perDay' => null !== $apiLimit ? (int) $apiLimit : $perDay,
                'perMinute' => $this->settings->openalexPerMinute(),
                // BLOQUÉ = 10000/10000 requêtes du jour épuisées ET aucun prépayé pour
                // prendre le relais. Tant qu'il reste du prépayé, la moisson tourne → pas de bandeau.
                'exhausted' => $blocked,
                'real' => null !== $apiLimit && null !== $apiRemaining,
                // Garde-fou interne (cadence configurable) — secondaire.
                'internalUsed' => $used,
                'internalPerDay' => $perDay,
                // Valeurs RÉELLES annoncées par OpenAlex (en-têtes X-RateLimit-*).
                'apiDailyLimit' => null !== $apiLimit ? (int) $apiLimit : null,
                'apiDailyRemaining' => null !== $apiRemaining ? (int) $apiRemaining : null,
                'creditLimitUsd' => $limitUsd,
                'creditRemainingUsd' => $remainingUsd,
                'creditSpentUsd' => (null !== $limitUsd && null !== $remainingUsd) ? round($limitUsd - $remainingUsd, 4) : null,
                'creditCostUsd' => $rlFresh ? $num('openalex.credit.cost_usd') : null,
                // Solde PRÉPAYÉ : balance persistante (ne se réinitialise pas chaque jour) →
                // on montre la DERNIÈRE valeur connue (avec creditUpdatedAt pour la fraîcheur).
                'prepaidRemainingUsd' => $prepaidUsd,
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
