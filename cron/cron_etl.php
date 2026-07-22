<?php

declare(strict_types=1);

// cron/cron_etl.php — ETL worker
// CLI-only. Reads the "etl" config from the spw_config store and runs each enabled
// job (extract from an external source → load into PostgreSQL target). Logs each job run
// to spw_etl_log (best-effort — tolerates the table being absent). When more than one
// job needs to run for real (not a dry run), jobs are executed in parallel as separate
// CLI child processes (see ETL_MAX_PARALLEL_JOBS), each logging and persisting its own
// incremental watermark independently — safe because config_save() is optimistic-locked
// and etl_persist_watermark() retries on conflict.
// Usage:
//   php cron_etl.php                 — run all scheduled jobs (respects frequency guard)
//   php cron_etl.php admin           — run all enabled jobs now (bypasses the window)
//   php cron_etl.php admin <jobId>   — run a single job now
//   php cron_etl.php admin <jobId> dry — dry run (validate + count, no writes)
//   php cron_etl.php _run <jobId> <triggeredBy> — internal: one job, invoked by the
//                                                  parallel dispatcher below. Not for
//                                                  direct/manual use.

const ETL_MAX_PARALLEL_JOBS = 4;

require_once __DIR__ . '/../includes/etl_cli.php';
etl_cli_boot();

/**
 * Run exactly one job (by id) against the current config and log the result to
 * spw_etl_log. Persists any new incremental watermark. Returns true on success.
 */
function etl_run_single_job(
    \PgSql\Connection $conn,
    array $config,
    string $jobId,
    string $triggeredBy,
    bool $dryRun
): bool {
    $job = null;
    foreach ((array)($config['jobs'] ?? []) as $j) {
        if (is_array($j) && (string)($j['id'] ?? '') === $jobId) {
            $job = $j;
            break;
        }
    }
    if ($job === null) {
        etl_cli_log("[etl] Job '{$jobId}' not found.");
        return false;
    }
    $jobName = (string)($job['name'] ?? $jobId);

    $connCfg = etl_resolve_source((array)($config['sources'] ?? []), (string)($job['source_id'] ?? ''));
    if ($connCfg === null) {
        etl_cli_log("[etl] Job '{$jobName}' has no valid source configured.");
        return false;
    }

    etl_cli_log("[etl] Job '{$jobName}' → {$job['target_table']} (" . ($job['load_mode'] ?? 'full_refresh') . ')...');

    $tLog     = sys_table('etl_log');
    $logTable = etl_log_table_ready($conn, $tLog);

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

    $prevWatermark = $job['last_watermark'] ?? ($job['incremental_initial_value'] ?? null);
    $wmParam       = $prevWatermark !== null ? (string)$prevWatermark : null;
    $result        = etl_run_job($conn, $job, $connCfg, $dryRun, $wmParam);

    if ($result['status'] === 'success') {
        etl_cli_log("[etl]   read {$result['rows_read']}, written {$result['rows_written']}.");
        $prevWm           = $job['last_watermark'] ?? null;
        $watermarkChanged = $result['new_watermark'] !== null && $result['new_watermark'] !== $prevWm;
        if (!$dryRun && $watermarkChanged) {
            etl_persist_watermark($jobId, $result['new_watermark'], 'etl:' . $jobName);
        }
    } else {
        etl_cli_log('[etl]   ERROR: ' . ($result['error'] ?? 'unknown'));
    }

    if ($logId !== null) {
        @pg_query_params(
            $conn,
            "UPDATE {$tLog} SET finished_at = now(), status = $1, rows_read = $2, rows_written = $3, error_message = $4 WHERE id = $5",
            [$result['status'], $result['rows_read'], $result['rows_written'], $result['error'], $logId]
        );
    }

    return $result['status'] === 'success';
}

// ---- internal worker mode: run exactly one job, invoked as a child process ----
if (($argv[1] ?? '') === '_run') {
    $jobId       = (string)($argv[2] ?? '');
    $triggeredBy = in_array($argv[3] ?? '', ['cron', 'admin'], true) ? $argv[3] : 'cron';

    $configRow = config_get_row('etl');
    if (!is_array($configRow)) {
        etl_cli_log('[etl] Config not found.');
        exit(1);
    }
    try {
        $conn = db_connect();
    } catch (\RuntimeException $e) {
        etl_cli_log('[etl] DB connection failed: ' . $e->getMessage());
        exit(1);
    }
    $ok = etl_run_single_job($conn, $configRow['value'], $jobId, $triggeredBy, false);
    exit($ok ? 0 : 1);
}

$triggeredBy = ($argv[1] ?? '') === 'admin' ? 'admin' : 'cron';
$onlyJobId   = ($triggeredBy === 'admin' && isset($argv[2]) && $argv[2] !== 'dry') ? (string)$argv[2] : null;
$dryRun      = (($argv[2] ?? '') === 'dry' || ($argv[3] ?? '') === 'dry');

etl_cli_log('[etl] Starting (' . $triggeredBy . ')' . ($dryRun ? ' — DRY RUN' : '') . '...');

$configRow = config_get_row('etl');
if (!is_array($configRow)) {
    etl_cli_log('[etl] Config not found. Configure it via Admin > ETL.');
    exit(1);
}
$config = $configRow['value'];

$enabled   = (bool)($config['enabled'] ?? false);
$frequency = (string)($config['frequency'] ?? 'daily');
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
$logTable = etl_log_table_ready($conn, $tLog);
if (!$logTable) {
    etl_cli_log('[etl] Note: log table missing — run Initialize System Tables to enable run history.');
}
$interval = etl_interval_expr($frequency);

/**
 * Whether a scheduled run already succeeded for this job inside the frequency window.
 * Per-job and scoped to triggered_by = 'cron', so a manual (admin) or flow-triggered
 * run — and other jobs' successes — never suppress this job's scheduled run.
 */
$ranInWindow = static function (string $jobId) use ($conn, $tLog, $interval): bool {
    $recent = @pg_query_params(
        $conn,
        "SELECT 1 FROM {$tLog} WHERE job_id = \$1 AND triggered_by = 'cron' AND status = 'success' "
        . "AND started_at >= NOW() - INTERVAL '{$interval}' LIMIT 1",
        [$jobId]
    );
    return $recent && pg_num_rows($recent) > 0;
};

// Which job ids will actually run.
$jobIds = [];
foreach ($jobs as $job) {
    if (!is_array($job)) {
        continue;
    }
    $jobId = (string)($job['id'] ?? '');
    if ($onlyJobId !== null && $jobId !== $onlyJobId) {
        continue;
    }
    // Scheduled runs skip disabled jobs; an explicit admin single-job run always runs.
    if ($onlyJobId === null && empty($job['enabled'])) {
        continue;
    }
    // Per-job frequency guard for scheduled runs.
    if ($triggeredBy === 'cron' && $logTable && $ranInWindow($jobId)) {
        etl_cli_log("[etl] Skipping job '{$jobId}': a scheduled run already succeeded within the '{$frequency}' window.");
        continue;
    }
    $jobIds[] = $jobId;
}

$anyError = false;

if ($dryRun || count($jobIds) <= 1) {
    // Dry runs and single-job runs stay in-process — no benefit from spawning children.
    foreach ($jobIds as $jobId) {
        if (!etl_run_single_job($conn, $config, $jobId, $triggeredBy, $dryRun)) {
            $anyError = true;
        }
    }
} else {
    // Multiple real jobs: run them concurrently as child processes, each independently
    // logging to spw_etl_log and persisting its own watermark.
    etl_cli_log('[etl] Running ' . count($jobIds) . ' jobs in parallel (max ' . ETL_MAX_PARALLEL_JOBS . ' at once)...');
    $cronScript = __FILE__;
    $queue      = $jobIds;
    $running    = []; // jobId => ['proc' => resource, 'pipes' => array]

    while ($queue !== [] || $running !== []) {
        while ($queue !== [] && count($running) < ETL_MAX_PARALLEL_JOBS) {
            $jobId = array_shift($queue);
            $cmd   = [PHP_BINARY, $cronScript, '_run', $jobId, $triggeredBy];
            $proc  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if ($proc === false) {
                etl_cli_log("[etl]   Failed to spawn worker for job '{$jobId}'.");
                $anyError = true;
                continue;
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[$jobId] = ['proc' => $proc, 'pipes' => $pipes];
        }

        foreach ($running as $jobId => $entry) {
            $out = stream_get_contents($entry['pipes'][1]);
            if ($out !== false && $out !== '') {
                echo $out;
                flush();
            }
            $err = stream_get_contents($entry['pipes'][2]);
            if ($err !== false && $err !== '') {
                echo $err;
                flush();
            }
            $status = proc_get_status($entry['proc']);
            if (!$status['running']) {
                fclose($entry['pipes'][1]);
                fclose($entry['pipes'][2]);
                $exitCode = proc_close($entry['proc']);
                if ($exitCode !== 0) {
                    $anyError = true;
                }
                unset($running[$jobId]);
            }
        }

        if ($running !== []) {
            usleep(150000);
        }
    }
}

etl_cli_log('[etl] Done.' . ($anyError ? ' Some jobs failed — see above.' : ''));
exit($anyError ? 1 : 0);
