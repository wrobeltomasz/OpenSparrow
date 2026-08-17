<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

const ETL_MAX_PARALLEL_JOBS = 4;

require_once __DIR__ . '/../includes/etl_cli.php';
etl_cli_boot();

function etl_run_single_job(
    \PgSql\Connection $conn,
    array $config,
    string $jobId,
    string $triggeredBy,
    bool $dryRun
): bool {
    $job = null;
    foreach ((array)($config['jobs'] ?? []) as $candidateJob) {
        if (is_array($candidateJob) && (string)($candidateJob['id'] ?? '') === $jobId) {
            $job = $candidateJob;
            break;
        }
    }
    if ($job === null) {
        etl_cli_log("[etl] Job '{$jobId}' not found.");
        return false;
    }
    $jobName = (string)($job['name'] ?? $jobId);

    $connConfig = etl_resolve_source((array)($config['sources'] ?? []), (string)($job['source_id'] ?? ''));
    if ($connConfig === null) {
        etl_cli_log("[etl] Job '{$jobName}' has no valid source configured.");
        return false;
    }

    etl_cli_log("[etl] Job '{$jobName}' → {$job['target_table']} (" . ($job['load_mode'] ?? 'full_refresh') . ')...');

    $etlLogTable     = sys_table('etl_log');
    $logTable = etl_log_table_ready($conn, $etlLogTable);

    $logId = null;
    if ($logTable && !$dryRun) {
        $insertResult = @pg_query_params(
            $conn,
            "INSERT INTO {$etlLogTable} (job_id, job_name, triggered_by, status)"
            . " VALUES ($1, $2, $3, 'running') RETURNING id",
            [$jobId, $jobName, $triggeredBy]
        );
        if ($insertResult && ($row = pg_fetch_assoc($insertResult))) {
            $logId = (int)$row['id'];
        }
    }

    $previousWatermark = $job['last_watermark'] ?? ($job['incremental_initial_value'] ?? null);
    $watermarkParameter       = $previousWatermark !== null ? (string)$previousWatermark : null;
    $result        = etl_run_job($conn, $job, $connConfig, $dryRun, $watermarkParameter);

    if ($result['status'] === 'success') {
        etl_cli_log("[etl]   read {$result['rows_read']}, written {$result['rows_written']}.");
        $previousWatermark           = $job['last_watermark'] ?? null;
        $watermarkChanged = $result['new_watermark'] !== null && $result['new_watermark'] !== $previousWatermark;
        if (!$dryRun && $watermarkChanged) {
            etl_persist_watermark($jobId, $result['new_watermark'], 'etl:' . $jobName);
        }
    } else {
        etl_cli_log('[etl]   ERROR: ' . ($result['error'] ?? 'unknown'));
    }

    if ($logId !== null) {
        @pg_query_params(
            $conn,
            "UPDATE {$etlLogTable} SET finished_at = now(), status = $1, rows_read = $2, "
                . "rows_written = $3, error_message = $4 WHERE id = $5",
            [$result['status'], $result['rows_read'], $result['rows_written'], $result['error'], $logId]
        );
    }

    return $result['status'] === 'success';
}

function cron_etl_main(array $argv): int
{
    if (($argv[1] ?? '') === '_run') {
        $jobId       = (string)($argv[2] ?? '');
        $triggeredBy = in_array($argv[3] ?? '', ['cron', 'admin'], true) ? $argv[3] : 'cron';

        $configRow = config_get_row('etl');
        if (!is_array($configRow)) {
            etl_cli_log('[etl] Config not found.');
            return 1;
        }
        try {
            $conn = db_connect();
        } catch (\RuntimeException $exception) {
            etl_cli_log('[etl] DB connection failed: ' . $exception->getMessage());
            return 1;
        }
        $success = etl_run_single_job($conn, $configRow['value'], $jobId, $triggeredBy, false);
        return $success ? 0 : 1;
    }

    $triggeredBy = ($argv[1] ?? '') === 'admin' ? 'admin' : 'cron';
    $onlyJobId   = ($triggeredBy === 'admin' && isset($argv[2]) && $argv[2] !== 'dry') ? (string)$argv[2] : null;
    $dryRun      = (($argv[2] ?? '') === 'dry' || ($argv[3] ?? '') === 'dry');

    etl_cli_log('[etl] Starting (' . $triggeredBy . ')' . ($dryRun ? ' — DRY RUN' : '') . '...');

    $configRow = config_get_row('etl');
    if (!is_array($configRow)) {
        etl_cli_log('[etl] Config not found. Configure it via Admin > ETL.');
        return 1;
    }
    $config = $configRow['value'];

    $enabled   = (bool)($config['enabled'] ?? false);
    $frequency = (string)($config['frequency'] ?? 'daily');
    $jobs      = (array)($config['jobs'] ?? []);

    if (!$enabled && $triggeredBy === 'cron') {
        etl_cli_log('[etl] Module is disabled. Exiting.');
        return 0;
    }
    if ($frequency === 'manual' && $triggeredBy === 'cron') {
        etl_cli_log('[etl] Frequency set to manual — only runs when triggered via admin panel.');
        return 0;
    }
    if (empty($jobs)) {
        etl_cli_log('[etl] No jobs configured. Exiting.');
        return 0;
    }

    try {
        $conn = db_connect();
    } catch (\RuntimeException $exception) {
        etl_cli_log('[etl] DB connection failed: ' . $exception->getMessage());
        return 1;
    }

    $etlLogTable     = sys_table('etl_log');
    $logTable = etl_log_table_ready($conn, $etlLogTable);
    if (!$logTable) {
        etl_cli_log('[etl] Note: log table missing — run Initialize System Tables to enable run history.');
    }
    $interval = etl_interval_expr($frequency);

    $ranInWindow = static function (string $jobId) use ($conn, $etlLogTable, $interval): bool {
        $recent = @pg_query_params(
            $conn,
            "SELECT 1 FROM {$etlLogTable} WHERE job_id = \$1 AND triggered_by = 'cron' AND status = 'success' "
            . "AND started_at >= NOW() - INTERVAL '{$interval}' LIMIT 1",
            [$jobId]
        );
        return $recent && pg_num_rows($recent) > 0;
    };

    $jobIds = [];
    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }
        $jobId = (string)($job['id'] ?? '');
        if ($onlyJobId !== null && $jobId !== $onlyJobId) {
            continue;
        }

        if ($onlyJobId === null && empty($job['enabled'])) {
            continue;
        }

        if ($triggeredBy === 'cron' && $logTable && $ranInWindow($jobId)) {
            etl_cli_log(
                "[etl] Skipping job '{$jobId}': a scheduled run already succeeded within the '{$frequency}' window."
            );
            continue;
        }
        $jobIds[] = $jobId;
    }

    $anyError = false;

    if ($dryRun || count($jobIds) <= 1) {
        foreach ($jobIds as $jobId) {
            if (!etl_run_single_job($conn, $config, $jobId, $triggeredBy, $dryRun)) {
                $anyError = true;
            }
        }
    } else {
        etl_cli_log(
            '[etl] Running ' . count($jobIds) . ' jobs in parallel (max '
            . ETL_MAX_PARALLEL_JOBS . ' at once)...'
        );
        $cronScript = __FILE__;
        $queue      = $jobIds;
        $running    = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < ETL_MAX_PARALLEL_JOBS) {
                $jobId = array_shift($queue);
                $command   = [PHP_BINARY, $cronScript, '_run', $jobId, $triggeredBy];
                $process  = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if ($process === false) {
                    etl_cli_log("[etl]   Failed to spawn worker for job '{$jobId}'.");
                    $anyError = true;
                    continue;
                }
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $running[$jobId] = ['proc' => $process, 'pipes' => $pipes];
            }

            foreach ($running as $jobId => $entry) {
                $output = stream_get_contents($entry['pipes'][1]);
                if ($output !== false && $output !== '') {
                    echo $output;
                    flush();
                }
                $error = stream_get_contents($entry['pipes'][2]);
                if ($error !== false && $error !== '') {
                    echo $error;
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
    return $anyError ? 1 : 0;
}

$argv = (array) ($_SERVER['argv'] ?? []);
exit(cron_etl_main($argv));
