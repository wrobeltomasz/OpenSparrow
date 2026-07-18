<?php

declare(strict_types=1);

// cron/cron_etl.php — ETL worker
// CLI-only. Reads the "etl" config from the spw_config store and runs each enabled
// job (extract from MySQL source → load into PostgreSQL target). Logs each job run
// to spw_etl_log (best-effort — tolerates the table being absent).
// Usage:
//   php cron_etl.php                 — run all scheduled jobs (respects frequency guard)
//   php cron_etl.php admin           — run all enabled jobs now (bypasses the window)
//   php cron_etl.php admin <jobId>   — run a single job now
//   php cron_etl.php admin <jobId> dry — dry run (validate + count, no writes)

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

@ini_set('output_buffering', 'off');
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_implicit_flush(true);

function etl_cli_log(string $msg): void
{
    echo $msg . "\n";
    echo str_pad('', 4096) . "\n";
    flush();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/config_store.php';
require_once __DIR__ . '/../includes/etl_engine.php';

$triggeredBy = ($argv[1] ?? '') === 'admin' ? 'admin' : 'cron';
$onlyJobId   = ($triggeredBy === 'admin' && isset($argv[2]) && $argv[2] !== 'dry') ? (string)$argv[2] : null;
$dryRun      = (($argv[2] ?? '') === 'dry' || ($argv[3] ?? '') === 'dry');

etl_cli_log('[etl] Starting (' . $triggeredBy . ')' . ($dryRun ? ' — DRY RUN' : '') . '...');

$config = config_get('etl');
if (!is_array($config)) {
    etl_cli_log('[etl] Config not found. Configure it via Admin > ETL.');
    exit(1);
}

$enabled   = (bool)($config['enabled'] ?? false);
$frequency = (string)($config['frequency'] ?? 'daily');
$connCfg   = (array)($config['connection'] ?? []);
$jobs      = (array)($config['jobs'] ?? []);

if (!$enabled && $triggeredBy === 'cron') {
    etl_cli_log('[etl] Module is disabled. Exiting.');
    exit(0);
}
if ($frequency === 'manual' && $triggeredBy === 'cron') {
    etl_cli_log('[etl] Frequency set to manual — only runs when triggered via admin panel.');
    exit(0);
}
if (empty($jobs)) {
    etl_cli_log('[etl] No jobs configured. Exiting.');
    exit(0);
}

try {
    $conn = db_connect();
} catch (\RuntimeException $e) {
    etl_cli_log('[etl] DB connection failed: ' . $e->getMessage());
    exit(1);
}

$tLog     = sys_table('etl_log');
$logTable = @pg_query($conn, "SELECT 1 FROM {$tLog} LIMIT 0") !== false;
if (!$logTable) {
    etl_cli_log('[etl] Note: log table missing — run Initialize System Tables to enable run history.');
}

// Frequency guard for scheduled runs — skip when a successful run exists in-window.
if ($triggeredBy === 'cron' && $logTable) {
    $intervalMap = ['daily' => '1 day', 'weekly' => '7 days', 'monthly' => '30 days'];
    $interval    = $intervalMap[$frequency] ?? '1 day';
    $recent      = @pg_query(
        $conn,
        "SELECT 1 FROM {$tLog} WHERE status = 'success' AND started_at >= NOW() - INTERVAL '{$interval}' LIMIT 1"
    );
    if ($recent && pg_num_rows($recent) > 0) {
        etl_cli_log("[etl] Skipping: a successful run exists within the '{$frequency}' window.");
        exit(0);
    }
}

$anyError = false;
foreach ($jobs as $job) {
    if (!is_array($job)) {
        continue;
    }
    $jobId   = (string)($job['id'] ?? '');
    $jobName = (string)($job['name'] ?? $jobId);

    if ($onlyJobId !== null && $jobId !== $onlyJobId) {
        continue;
    }
    // Scheduled runs skip disabled jobs; an explicit admin single-job run always runs.
    if ($onlyJobId === null && empty($job['enabled'])) {
        continue;
    }

    etl_cli_log("[etl] Job '{$jobName}' → {$job['target_table']} (" . ($job['load_mode'] ?? 'full_refresh') . ')...');

    $logId = null;
    if ($logTable && !$dryRun) {
        $ins = @pg_query_params(
            $conn,
            "INSERT INTO {$tLog} (job_id, job_name, triggered_by, status) VALUES ($1, $2, $3, 'running') RETURNING id",
            [$jobId, $jobName, $triggeredBy]
        );
        if ($ins && ($r = pg_fetch_assoc($ins))) {
            $logId = (int)$r['id'];
        }
    }

    $result = etl_run_job($conn, $job, $connCfg, $dryRun);

    if ($result['status'] === 'success') {
        etl_cli_log("[etl]   read {$result['rows_read']}, written {$result['rows_written']}.");
    } else {
        $anyError = true;
        etl_cli_log('[etl]   ERROR: ' . ($result['error'] ?? 'unknown'));
    }

    if ($logId !== null) {
        @pg_query_params(
            $conn,
            "UPDATE {$tLog} SET finished_at = now(), status = $1, rows_read = $2, rows_written = $3, error_message = $4 WHERE id = $5",
            [$result['status'], $result['rows_read'], $result['rows_written'], $result['error'], $logId]
        );
    }
}

etl_cli_log('[etl] Done.' . ($anyError ? ' Some jobs failed — see above.' : ''));
exit($anyError ? 1 : 0);
