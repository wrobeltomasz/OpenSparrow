<?php

declare(strict_types=1);

// cron/cron_etl_flow.php — ETL Flow worker.
// CLI-only. Reads the "etl_flows" config from the spw_config store and runs each
// enabled flow: an ordered chain of existing ETL job ids (from the "etl" config),
// executed strictly in sequence, stopping at the first failing step. Flows run one
// at a time in-process — a chain of steps has no parallelism to exploit, unlike
// independent jobs in cron_etl.php.
// Logs each flow run to spw_etl_flow_run_log (summary) and spw_etl_flow_step_log
// (per-step detail), and also logs each step to spw_etl_log (triggered_by = 'flow')
// so the Jobs > History tab shows flow-triggered runs too — all best-effort,
// tolerates the tables being absent.
// Usage:
//   php cron_etl_flow.php                    — run all scheduled flows (respects frequency guard)
//   php cron_etl_flow.php admin              — run all enabled flows now
//   php cron_etl_flow.php admin <flowId>     — run a single flow now
//   php cron_etl_flow.php admin <flowId> dry — dry run: every step runs with dryRun=true

require_once __DIR__ . '/../includes/etl_cli.php';
etl_cli_boot();

/**
 * Best-effort: write the flow's last_run_status/last_run_at back into the
 * "etl_flows" config, retrying on an optimistic-lock conflict.
 */
function etl_flow_persist_last_run(string $flowId, string $status, string $whenIso): void
{
    etl_config_optimistic_update('etl_flows', static function (array &$config) use ($flowId, $status, $whenIso) {
        foreach ($config['flows'] ?? [] as $i => $f) {
            if ((string)($f['id'] ?? '') === $flowId) {
                $config['flows'][$i]['last_run_status'] = $status;
                $config['flows'][$i]['last_run_at']     = $whenIso;
                return true;
            }
        }
        return false;
    }, 'etl_flow');
}

/**
 * Run exactly one flow (by id) as a sequential chain of its steps' ETL jobs,
 * stopping at the first failing step. Returns true when every step succeeded.
 */
function etl_flow_run_single(
    \PgSql\Connection $conn,
    array $etlConfig,
    array $flow,
    string $triggeredBy,
    bool $dryRun
): bool {
    $flowId   = (string)($flow['id'] ?? '');
    $flowName = (string)($flow['name'] ?? $flowId);
    $steps    = array_values((array)($flow['steps'] ?? []));

    etl_cli_log("[etl_flow] Flow '{$flowName}' — " . count($steps) . ' step(s)...');

    $tRunLog  = sys_table('etl_flow_run_log');
    $tStepLog = sys_table('etl_flow_step_log');
    $logTable = etl_log_table_ready($conn, $tRunLog);

    // Also log each step to spw_etl_log, the same table cron_etl.php writes to, so a
    // job's run history (Jobs > History tab) shows flow-triggered runs alongside
    // directly-triggered ones — tagged triggered_by = 'flow' to tell them apart.
    $tJobLog     = sys_table('etl_log');
    $jobLogTable = etl_log_table_ready($conn, $tJobLog);

    $runLogId = null;
    if ($logTable && !$dryRun) {
        $ins = @pg_query_params(
            $conn,
            "INSERT INTO {$tRunLog} (flow_id, flow_name, triggered_by, status) VALUES ($1, $2, $3, 'running') RETURNING id",
            [$flowId, $flowName, $triggeredBy]
        );
        if ($ins && ($r = pg_fetch_assoc($ins))) {
            $runLogId = (int)$r['id'];
        }
    }

    $jobsById = [];
    foreach ((array)($etlConfig['jobs'] ?? []) as $j) {
        if (is_array($j) && (string)($j['id'] ?? '') !== '') {
            $jobsById[(string)$j['id']] = $j;
        }
    }
    $sources = (array)($etlConfig['sources'] ?? []);

    $allOk           = true;
    $failedStepIndex = null;
    $errorMessage    = null;

    foreach ($steps as $stepIndex => $jobId) {
        $job = $jobsById[$jobId] ?? null;
        if ($job === null) {
            $errorMessage    = "Step " . ($stepIndex + 1) . ": job '{$jobId}' not found.";
            $failedStepIndex = $stepIndex;
            etl_cli_log('[etl_flow]   ERROR: ' . $errorMessage);
            $allOk = false;
            break;
        }
        $jobName = (string)($job['name'] ?? $jobId);
        etl_cli_log("[etl_flow]   Step " . ($stepIndex + 1) . ": '{$jobName}'...");

        $connCfg = etl_resolve_source($sources, (string)($job['source_id'] ?? ''));
        if ($connCfg === null) {
            $errorMessage    = "Step " . ($stepIndex + 1) . " ('{$jobName}'): no valid source configured.";
            $failedStepIndex = $stepIndex;
            etl_cli_log('[etl_flow]   ERROR: ' . $errorMessage);
            $allOk = false;
            break;
        }

        $stepLogId = null;
        if ($logTable && !$dryRun) {
            $ins = @pg_query_params(
                $conn,
                "INSERT INTO {$tStepLog} (flow_run_id, flow_id, step_index, job_id, job_name, status)
                 VALUES ($1, $2, $3, $4, $5, 'running') RETURNING id",
                [$runLogId, $flowId, $stepIndex, $jobId, $jobName]
            );
            if ($ins && ($r = pg_fetch_assoc($ins))) {
                $stepLogId = (int)$r['id'];
            }
        }

        $jobLogId = null;
        if ($jobLogTable && !$dryRun) {
            $ins = @pg_query_params(
                $conn,
                "INSERT INTO {$tJobLog} (job_id, job_name, triggered_by, status) VALUES ($1, $2, 'flow', 'running') RETURNING id",
                [$jobId, $jobName]
            );
            if ($ins && ($r = pg_fetch_assoc($ins))) {
                $jobLogId = (int)$r['id'];
            }
        }

        $prevWatermark = $job['last_watermark'] ?? ($job['incremental_initial_value'] ?? null);
        $wmParam       = $prevWatermark !== null ? (string)$prevWatermark : null;
        $result        = etl_run_job($conn, $job, $connCfg, $dryRun, $wmParam);

        if ($stepLogId !== null) {
            @pg_query_params(
                $conn,
                "UPDATE {$tStepLog} SET finished_at = now(), status = $1, rows_read = $2, rows_written = $3, error_message = $4 WHERE id = $5",
                [$result['status'], $result['rows_read'], $result['rows_written'], $result['error'], $stepLogId]
            );
        }
        if ($jobLogId !== null) {
            @pg_query_params(
                $conn,
                "UPDATE {$tJobLog} SET finished_at = now(), status = $1, rows_read = $2, rows_written = $3, error_message = $4 WHERE id = $5",
                [$result['status'], $result['rows_read'], $result['rows_written'], $result['error'], $jobLogId]
            );
        }

        if ($result['status'] !== 'success') {
            $errorMessage    = "Step " . ($stepIndex + 1) . " ('{$jobName}'): " . ($result['error'] ?? 'unknown error');
            $failedStepIndex = $stepIndex;
            etl_cli_log('[etl_flow]   ERROR: ' . ($result['error'] ?? 'unknown'));
            $allOk = false;
            break;
        }

        etl_cli_log("[etl_flow]     read {$result['rows_read']}, written {$result['rows_written']}.");
        $prevWm           = $job['last_watermark'] ?? null;
        $watermarkChanged = $result['new_watermark'] !== null && $result['new_watermark'] !== $prevWm;
        if (!$dryRun && $watermarkChanged) {
            etl_persist_watermark($jobId, $result['new_watermark'], 'etl_flow:' . $flowName);
        }
    }

    if ($runLogId !== null) {
        @pg_query_params(
            $conn,
            "UPDATE {$tRunLog} SET finished_at = now(), status = $1, failed_step_index = $2, error_message = $3 WHERE id = $4",
            [$allOk ? 'success' : 'error', $failedStepIndex, $errorMessage, $runLogId]
        );
    }

    if (!$dryRun) {
        etl_flow_persist_last_run($flowId, $allOk ? 'success' : 'error', date('c'));
    }

    etl_cli_log("[etl_flow] Flow '{$flowName}' " . ($allOk ? 'completed.' : 'FAILED.'));
    return $allOk;
}

$triggeredBy = ($argv[1] ?? '') === 'admin' ? 'admin' : 'cron';
$onlyFlowId  = ($triggeredBy === 'admin' && isset($argv[2]) && $argv[2] !== 'dry') ? (string)$argv[2] : null;
$dryRun      = (($argv[2] ?? '') === 'dry' || ($argv[3] ?? '') === 'dry');

etl_cli_log('[etl_flow] Starting (' . $triggeredBy . ')' . ($dryRun ? ' — DRY RUN' : '') . '...');

$flowsRow = config_get_row('etl_flows');
if (!is_array($flowsRow)) {
    etl_cli_log('[etl_flow] Config not found. Configure it via Admin > ETL > Flows.');
    exit(0);
}
$flowsConfig = $flowsRow['value'];

$enabled   = (bool)($flowsConfig['enabled'] ?? false);
$frequency = (string)($flowsConfig['frequency'] ?? 'daily');
$flows     = (array)($flowsConfig['flows'] ?? []);

if (!$enabled && $triggeredBy === 'cron') {
    etl_cli_log('[etl_flow] Module is disabled. Exiting.');
    exit(0);
}
if ($frequency === 'manual' && $triggeredBy === 'cron') {
    etl_cli_log('[etl_flow] Frequency set to manual — only runs when triggered via admin panel.');
    exit(0);
}
if (empty($flows)) {
    etl_cli_log('[etl_flow] No flows configured. Exiting.');
    exit(0);
}

$etlRow = config_get_row('etl');
$etlConfig = is_array($etlRow['value'] ?? null) ? $etlRow['value'] : ['sources' => [], 'jobs' => []];

try {
    $conn = db_connect();
} catch (\RuntimeException $e) {
    etl_cli_log('[etl_flow] DB connection failed: ' . $e->getMessage());
    exit(1);
}

$tRunLog  = sys_table('etl_flow_run_log');
$logTable = etl_log_table_ready($conn, $tRunLog);
if (!$logTable) {
    etl_cli_log('[etl_flow] Note: log tables missing — run Initialize System Tables to enable run history.');
}

$interval = etl_interval_expr($frequency);

$anyError = false;

foreach ($flows as $flow) {
    if (!is_array($flow)) {
        continue;
    }
    $flowId = (string)($flow['id'] ?? '');
    if ($onlyFlowId !== null && $flowId !== $onlyFlowId) {
        continue;
    }
    // Scheduled runs skip disabled flows; an explicit admin single-flow run always runs.
    if ($onlyFlowId === null && empty($flow['enabled'])) {
        continue;
    }

    // Frequency guard for scheduled runs — per flow, since flows can have different
    // last-success times, unlike cron_etl.php's module-wide guard.
    if ($triggeredBy === 'cron' && $logTable) {
        $recent = @pg_query_params(
            $conn,
            "SELECT 1 FROM {$tRunLog} WHERE flow_id = $1 AND status = 'success' AND started_at >= NOW() - INTERVAL '{$interval}' LIMIT 1",
            [$flowId]
        );
        if ($recent && pg_num_rows($recent) > 0) {
            etl_cli_log("[etl_flow] Skipping flow '{$flowId}': a successful run exists within the '{$frequency}' window.");
            continue;
        }
    }

    if (!etl_flow_run_single($conn, $etlConfig, $flow, $triggeredBy, $dryRun)) {
        $anyError = true;
    }
}

etl_cli_log('[etl_flow] Done.' . ($anyError ? ' Some flows failed — see above.' : ''));
exit($anyError ? 1 : 0);
