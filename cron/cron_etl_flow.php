<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/etl_cli.php';
etl_cli_boot();

function etl_flow_persist_last_run(string $flowId, string $status, string $whenIso): void
{
    etl_config_optimistic_update('etl_flows', static function (array &$config) use ($flowId, $status, $whenIso) {
        foreach ($config['flows'] ?? [] as $flowIndex => $flow) {
            if ((string)($flow['id'] ?? '') === $flowId) {
                $config['flows'][$flowIndex]['last_run_status'] = $status;
                $config['flows'][$flowIndex]['last_run_at']     = $whenIso;
                return true;
            }
        }
        return false;
    }, 'etl_flow');
}

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

    $etlFlowRunLogTable  = sys_table('etl_flow_run_log');
    $etlFlowStepLogTable = sys_table('etl_flow_step_log');
    $logTable = etl_log_table_ready($conn, $etlFlowRunLogTable);

    $etlLogTable     = sys_table('etl_log');
    $jobLogTable = etl_log_table_ready($conn, $etlLogTable);

    $runLogId = null;
    if ($logTable && !$dryRun) {
        $insertResult = @pg_query_params(
            $conn,
            "INSERT INTO {$etlFlowRunLogTable} (flow_id, flow_name, triggered_by, status) "
                . "VALUES ($1, $2, $3, 'running') RETURNING id",
            [$flowId, $flowName, $triggeredBy]
        );
        if ($insertResult && ($row = pg_fetch_assoc($insertResult))) {
            $runLogId = (int)$row['id'];
        }
    }

    $jobsById = [];
    foreach ((array)($etlConfig['jobs'] ?? []) as $etlJob) {
        if (is_array($etlJob) && (string)($etlJob['id'] ?? '') !== '') {
            $jobsById[(string)$etlJob['id']] = $etlJob;
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
            $insertResult = @pg_query_params(
                $conn,
                "INSERT INTO {$etlFlowStepLogTable} (flow_run_id, flow_id, step_index, job_id, job_name, status)
                 VALUES ($1, $2, $3, $4, $5, 'running') RETURNING id",
                [$runLogId, $flowId, $stepIndex, $jobId, $jobName]
            );
            if ($insertResult && ($row = pg_fetch_assoc($insertResult))) {
                $stepLogId = (int)$row['id'];
            }
        }

        $jobLogId = null;
        if ($jobLogTable && !$dryRun) {
            $insertResult = @pg_query_params(
                $conn,
                "INSERT INTO {$etlLogTable} (job_id, job_name, triggered_by, status) "
                    . "VALUES ($1, $2, 'flow', 'running') RETURNING id",
                [$jobId, $jobName]
            );
            if ($insertResult && ($row = pg_fetch_assoc($insertResult))) {
                $jobLogId = (int)$row['id'];
            }
        }

        $prevWatermark = $job['last_watermark'] ?? ($job['incremental_initial_value'] ?? null);
        $watermarkParam       = $prevWatermark !== null ? (string)$prevWatermark : null;
        $result        = etl_run_job($conn, $job, $connCfg, $dryRun, $watermarkParam);

        if ($stepLogId !== null) {
            @pg_query_params(
                $conn,
                "UPDATE {$etlFlowStepLogTable} SET finished_at = now(), status = $1, rows_read = $2, "
                    . "rows_written = $3, error_message = $4 WHERE id = $5",
                [$result['status'], $result['rows_read'], $result['rows_written'], $result['error'], $stepLogId]
            );
        }
        if ($jobLogId !== null) {
            @pg_query_params(
                $conn,
                "UPDATE {$etlLogTable} SET finished_at = now(), status = $1, rows_read = $2, "
                    . "rows_written = $3, error_message = $4 WHERE id = $5",
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
            "UPDATE {$etlFlowRunLogTable} SET finished_at = now(), status = $1, "
                . "failed_step_index = $2, error_message = $3 WHERE id = $4",
            [$allOk ? 'success' : 'error', $failedStepIndex, $errorMessage, $runLogId]
        );
    }

    if (!$dryRun) {
        etl_flow_persist_last_run($flowId, $allOk ? 'success' : 'error', date('c'));
    }

    etl_cli_log("[etl_flow] Flow '{$flowName}' " . ($allOk ? 'completed.' : 'FAILED.'));
    return $allOk;
}

function cron_etl_flow_main(array $argv): int
{
    $triggeredBy = ($argv[1] ?? '') === 'admin' ? 'admin' : 'cron';
    $onlyFlowId  = ($triggeredBy === 'admin' && isset($argv[2]) && $argv[2] !== 'dry') ? (string)$argv[2] : null;
    $dryRun      = (($argv[2] ?? '') === 'dry' || ($argv[3] ?? '') === 'dry');

    etl_cli_log('[etl_flow] Starting (' . $triggeredBy . ')' . ($dryRun ? ' — DRY RUN' : '') . '...');

    $flowsRow = config_get_row('etl_flows');
    if (!is_array($flowsRow)) {
        etl_cli_log('[etl_flow] Config not found. Configure it via Admin > ETL > Flows.');
        return 0;
    }
    $flowsConfig = $flowsRow['value'];

    $enabled   = (bool)($flowsConfig['enabled'] ?? false);
    $frequency = (string)($flowsConfig['frequency'] ?? 'daily');
    $flows     = (array)($flowsConfig['flows'] ?? []);

    if (!$enabled && $triggeredBy === 'cron') {
        etl_cli_log('[etl_flow] Module is disabled. Exiting.');
        return 0;
    }
    if ($frequency === 'manual' && $triggeredBy === 'cron') {
        etl_cli_log('[etl_flow] Frequency set to manual — only runs when triggered via admin panel.');
        return 0;
    }
    if (empty($flows)) {
        etl_cli_log('[etl_flow] No flows configured. Exiting.');
        return 0;
    }

    $etlRow = config_get_row('etl');
    $etlConfig = is_array($etlRow['value'] ?? null) ? $etlRow['value'] : ['sources' => [], 'jobs' => []];

    try {
        $conn = db_connect();
    } catch (\RuntimeException $exception) {
        etl_cli_log('[etl_flow] DB connection failed: ' . $exception->getMessage());
        return 1;
    }

    $etlFlowRunLogTable  = sys_table('etl_flow_run_log');
    $logTable = etl_log_table_ready($conn, $etlFlowRunLogTable);
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

        if ($onlyFlowId === null && empty($flow['enabled'])) {
            continue;
        }

        if ($triggeredBy === 'cron' && $logTable) {
            $recent = @pg_query_params(
                $conn,
                "SELECT 1 FROM {$etlFlowRunLogTable} WHERE flow_id = $1 AND status = 'success' "
                    . "AND started_at >= NOW() - INTERVAL '{$interval}' LIMIT 1",
                [$flowId]
            );
            if ($recent && pg_num_rows($recent) > 0) {
                etl_cli_log(
                    "[etl_flow] Skipping flow '{$flowId}': a successful run exists within the '{$frequency}' window."
                );
                continue;
            }
        }

        if (!etl_flow_run_single($conn, $etlConfig, $flow, $triggeredBy, $dryRun)) {
            $anyError = true;
        }
    }

    etl_cli_log('[etl_flow] Done.' . ($anyError ? ' Some flows failed — see above.' : ''));
    return $anyError ? 1 : 0;
}

$argv = (array) ($_SERVER['argv'] ?? []);
exit(cron_etl_flow_main($argv));
