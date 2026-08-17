<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ResponseException;

if ($action === 'etl_flow_load') {
    require_once __DIR__ . '/../config_store.php';
    $defaults = [
        'enabled'   => false,
        'frequency' => 'daily',
        'flows'     => [],
    ];
    $row    = config_get_row('etl_flows');
    $config = is_array($row['value'] ?? null) ? array_merge($defaults, $row['value']) : $defaults;
    $config['flows'] = is_array($config['flows'] ?? null) ? $config['flows'] : [];

    $etlJobs = (array)(config_get('etl')['jobs'] ?? []);
    $jobs    = [];
    foreach ($etlJobs as $job) {
        if (is_array($job) && (string)($job['id'] ?? '') !== '') {
            $jobs[] = [
                'id'      => (string)$job['id'],
                'name'    => (string)($job['name'] ?? $job['id']),
                'enabled' => (bool)($job['enabled'] ?? true),
            ];
        }
    }

    echo json_encode([
        'status'  => 'success',
        'config'  => $config,
        'jobs'    => $jobs,
        'version' => $row['version'] ?? 0,
    ]);
    throw ResponseException::sent();
}

if ($action === 'etl_flow_save') {
    require_not_demo('Demo mode — writes disabled.');
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        admin_err('Invalid JSON.');
    }
    require_once __DIR__ . '/../config_store.php';
    $existing = config_get('etl_flows');
    $existing = is_array($existing) ? $existing : [];

    $existingFlowsById = [];
    foreach ((array)($existing['flows'] ?? []) as $existingFlow) {
        if (is_array($existingFlow) && ($existingFlow['id'] ?? '') !== '') {
            $existingFlowsById[(string)$existingFlow['id']] = $existingFlow;
        }
    }

    $etlJobs = (array)(config_get('etl')['jobs'] ?? []);
    $validJobIds = [];
    foreach ($etlJobs as $job) {
        if (is_array($job) && (string)($job['id'] ?? '') !== '') {
            $validJobIds[] = (string)$job['id'];
        }
    }

    $validFrequencies = ['manual', 'daily', 'weekly', 'monthly'];

    $flows = [];
    foreach ((array)($data['flows'] ?? []) as $flow) {
        if (!is_array($flow)) {
            continue;
        }
        $name = trim((string)($flow['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $id = trim((string)($flow['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }

        $steps = [];
        foreach ((array)($flow['steps'] ?? []) as $jobId) {
            $jobId = trim((string)$jobId);
            if ($jobId !== '' && in_array($jobId, $validJobIds, true)) {
                $steps[] = $jobId;
            }
        }

        $previous = $existingFlowsById[$id] ?? [];
        $flows[] = [
            'id'              => $id,
            'name'            => $name,
            'enabled'         => (bool)($flow['enabled'] ?? true),
            'steps'           => $steps,

            'last_run_status' => $previous['last_run_status'] ?? null,
            'last_run_at'     => $previous['last_run_at'] ?? null,
        ];
    }

    $config = [
        'enabled'   => (bool)($data['enabled'] ?? false),
        'frequency' => in_array($data['frequency'] ?? '', $validFrequencies, true) ? $data['frequency'] : 'daily',
        'flows'     => $flows,
    ];

    admin_config_save_versioned('etl_flows', $config, admin_expected_version($data));
}

if ($action === 'run_etl_flow') {
    require_not_demo('Demo mode — writes disabled.');
    require_once __DIR__ . '/etl_common.php';
    $data   = json_decode((string) file_get_contents('php://input'), true);
    $flowId = trim((string)($data['flow_id'] ?? ''));
    etl_admin_run_cron_script(__DIR__ . '/../../cron/cron_etl_flow.php', $flowId, 'ETL Flow cron script not found.');
}

if ($action === 'etl_flow_log') {
    admin_try(static function (): void {
        $conn    = admin_conn();
        $etlFlowRunLogTable = sys_table('etl_flow_run_log');
        admin_require_log_table($conn, $etlFlowRunLogTable);
        $flowId = trim(os_request()->query('flow_id'));
        $sql = "SELECT id, flow_id, flow_name, triggered_by, status, failed_step_index, error_message,
                       started_at, finished_at,
                       EXTRACT(EPOCH FROM (COALESCE(finished_at, now()) - started_at)) AS duration_sec
                FROM {$etlFlowRunLogTable}";
        $parameters = [];
        if ($flowId !== '') {
            $sql      .= ' WHERE flow_id = $1';
            $parameters[] = $flowId;
        }
        $sql .= ' ORDER BY started_at DESC LIMIT 50';
        $result = @pg_query_params($conn, $sql, $parameters);
        if (!$result) {
            admin_db_fail($conn, 'etl_flow_log');
        }
        admin_ok(['rows' => admin_fetch_all($result)]);
    });
}

if ($action === 'etl_flow_purge_log') {
    require_not_demo('Demo mode — writes disabled.');
    admin_try(static fn() => admin_purge_log(
        sys_table('etl_flow_run_log'),
        90,
        'etl_flow_purge_log',
        'started_at',
        static function (\PgSql\Connection $conn): void {
            $etlFlowRunLogTable  = sys_table('etl_flow_run_log');
            $etlFlowStepLogTable = sys_table('etl_flow_step_log');
            @pg_query(
                $conn,
                "DELETE FROM {$etlFlowStepLogTable}"
                . " WHERE flow_run_id NOT IN (SELECT id FROM {$etlFlowRunLogTable})"
            );
        }
    ));
}
