<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Harvester\Command\OpenAlexSnapshotIngestCommand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Suivi temps réel de l'ingestion du snapshot OpenAlex pour le back-office
 * (onglet « Snapshot OpenAlex » de l'écran de moisson). Lit la progression
 * publiée par la commande dans la clé setting JSON + un échantillon des
 * dernières publications intégrées. ROLE_ADMIN (firewall /api/admin).
 */
final class AdminSnapshotController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/admin/harvest/snapshot-status', name: 'admin_snapshot_status', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $conn = $this->em->getConnection();

        $raw = $conn->fetchOne('SELECT value FROM setting WHERE name = :n', ['n' => OpenAlexSnapshotIngestCommand::PROGRESS_KEY]);
        $progress = \is_string($raw) && '' !== $raw ? json_decode($raw, true) : null;

        $derived = null;
        if (\is_array($progress)) {
            $derived = $this->derive($progress);
        }

        // Échantillon des dernières publications intégrées (la moisson API étant en
        // pause, les plus récentes sont celles du snapshot). Abstract tronqué.
        $samples = $conn->fetchAllAssociative(
            "WITH recent AS (SELECT * FROM publication ORDER BY id DESC LIMIT 12)
             SELECT p.id, p.title,
                    LEFT(COALESCE(p.abstract, ''), 320) AS abstract,
                    (p.abstract IS NOT NULL AND p.abstract <> '') AS has_abstract,
                    p.doi, p.venue, p.language, p.type, p.cited_by_count, p.oa_status,
                    p.oa_url, p.landing_page_url,
                    to_char(p.publication_date, 'YYYY-MM-DD') AS pub_date,
                    to_char(p.created_at, 'YYYY-MM-DD\"T\"HH24:MI:SSZ') AS created_at,
                    dom.domain AS domain,
                    auth.names AS authors
             FROM recent p
             LEFT JOIN LATERAL (
                 SELECT tn.domain FROM placement_suggestion ps
                 JOIN tree_node tn ON tn.id = ps.tree_node_id
                 WHERE ps.publication_id = p.id AND tn.domain IS NOT NULL
                 ORDER BY ps.score DESC LIMIT 1
             ) dom ON true
             LEFT JOIN LATERAL (
                 SELECT string_agg(a.name, ', ' ORDER BY au.position) AS names
                 FROM (SELECT author_id, position FROM authorship WHERE publication_id = p.id ORDER BY position LIMIT 6) au
                 JOIN author a ON a.id = au.author_id
             ) auth ON true
             ORDER BY p.id DESC"
        );

        foreach ($samples as &$s) {
            $s['id'] = (int) $s['id'];
            $s['cited_by_count'] = (int) $s['cited_by_count'];
            $s['has_abstract'] = (bool) $s['has_abstract'];
            $s['url'] = $this->bestUrl($s);
        }
        unset($s);

        return new JsonResponse([
            'active' => \is_array($progress) && !($progress['finished'] ?? false),
            'progress' => $progress,
            'derived' => $derived,
            'samples' => $samples,
        ]);
    }

    /**
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function derive(array $p): array
    {
        $total = max(1, (int) ($p['total_files'] ?? 1));
        $done = (int) ($p['done_files'] ?? 0);
        $skip = (int) ($p['skip_files'] ?? 0);
        $finished = (bool) ($p['finished'] ?? false);

        $started = strtotime((string) ($p['started_at'] ?? '')) ?: null;
        $updated = strtotime((string) ($p['updated_at'] ?? '')) ?: null;
        $now = time();
        $elapsedRun = ($started && $updated) ? max(0, $updated - $started) : 0;
        $sinceUpdate = $updated ? max(0, $now - $updated) : 0;

        $processedRun = max(0, $done - $skip);          // fichiers traités CE run
        $filesPerSec = ($elapsedRun > 0) ? $processedRun / $elapsedRun : 0.0;
        $remaining = max(0, $total - $done);
        $etaSec = ($filesPerSec > 0 && !$finished) ? (int) round($remaining / $filesPerSec) : null;

        $scanned = (int) ($p['scanned'] ?? 0);
        $selected = (int) ($p['selected'] ?? 0);

        return [
            'percent' => round($done / $total * 100, 2),
            'done_files' => $done,
            'total_files' => $total,
            'remaining_files' => $remaining,
            'elapsed_run_sec' => $elapsedRun,
            'since_update_sec' => $sinceUpdate,         // « silence » : si grand, le run est peut-être mort
            'files_per_min' => round($filesPerSec * 60, 2),
            'eta_sec' => $etaSec,                         // compte à rebours (recalculé chaque tick côté UI)
            'eta_finish_at' => $etaSec !== null ? gmdate('c', $now + $etaSec) : null,
            'scanned' => $scanned,
            'selected' => $selected,
            'retention_pct' => $scanned > 0 ? round($selected / $scanned * 100, 3) : 0.0,
            'projected_selected' => ($scanned > 0 && $total > 0)
                ? (int) round($selected / max(1, $done) * $total) : null,
            'finished' => $finished,
            'stale' => $sinceUpdate > 1800,              // >30 min sans MAJ = suspect
        ];
    }

    /** @param array<string,mixed> $s */
    private function bestUrl(array $s): ?string
    {
        if (!empty($s['oa_url'])) {
            return (string) $s['oa_url'];
        }
        if (!empty($s['landing_page_url'])) {
            return (string) $s['landing_page_url'];
        }
        if (!empty($s['doi'])) {
            return 'https://doi.org/'.$s['doi'];
        }

        return null;
    }
}
