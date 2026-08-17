<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

function etl_migrate_legacy_connection(array $config): array
{
    if (!empty($config['sources']) || empty($config['connection'])) {
        unset($config['connection']);
        $config['sources'] = is_array($config['sources'] ?? null) ? $config['sources'] : [];
        $config['jobs']    = is_array($config['jobs'] ?? null) ? $config['jobs'] : [];
        return $config;
    }
    $legacy = (array) $config['connection'];
    $source = [
        'id'             => 'legacy',
        'name'           => 'Default source',
        'driver'         => $legacy['driver'] ?? 'mysql',
        'host'           => $legacy['host'] ?? '',
        'port'           => $legacy['port'] ?? 3306,
        'database'       => $legacy['database'] ?? '',
        'user'           => $legacy['user'] ?? '',
        'password'       => $legacy['password'] ?? '',
        'protocol'       => 'ftp',
        'remote_dir'     => '',
        'file_name'      => '',
        'csv_delimiter'  => ',',
        'csv_has_header' => true,
        'passive_mode'   => true,
    ];
    $config['sources'] = [$source];
    foreach ((array)($config['jobs'] ?? []) as $jobIndex => $job) {
        if (is_array($job) && empty($job['source_id'])) {
            $config['jobs'][$jobIndex]['source_id'] = 'legacy';
        }
    }
    unset($config['connection']);
    return $config;
}

function etl_stored_source_password(string $sourceId): string
{
    if ($sourceId === '') {
        return '';
    }
    require_once __DIR__ . '/../config_store.php';
    $stored = etl_migrate_legacy_connection(config_get('etl') ?: []);
    foreach ((array)($stored['sources'] ?? []) as $sourceEntry) {
        if (is_array($sourceEntry) && (string)($sourceEntry['id'] ?? '') === $sourceId) {
            return (string)($sourceEntry['password'] ?? '');
        }
    }
    return '';
}

if ($action === 'etl_load') {
    require_once __DIR__ . '/../config_store.php';
    $defaults = [
        'enabled'   => false,
        'frequency' => 'daily',
        'sources'   => [],
        'jobs'      => [],
    ];
    $row    = config_get_row('etl');
    $config = is_array($row['value'] ?? null) ? array_merge($defaults, $row['value']) : $defaults;
    $config = etl_migrate_legacy_connection($config);

    require_once __DIR__ . '/../db.php';
    foreach ($config['jobs'] as $jobIndex => $job) {
        if (is_array($job) && trim((string)($job['target_schema'] ?? '')) === '') {
            $config['jobs'][$jobIndex]['target_schema'] = sys_schema();
        }
    }

    foreach ($config['sources'] as $sourceIndex => $sourceEntry) {
        if (is_array($sourceEntry) && trim((string)($sourceEntry['id'] ?? '')) === '') {
            $oldId = (string)($sourceEntry['id'] ?? '');
            $newId = bin2hex(random_bytes(8));
            $config['sources'][$sourceIndex]['id'] = $newId;
            foreach ($config['jobs'] as $jobIndex => $job) {
                if (is_array($job) && (string)($job['source_id'] ?? '') === $oldId) {
                    $config['jobs'][$jobIndex]['source_id'] = $newId;
                }
            }
        }
    }

    foreach ($config['sources'] as $sourceIndex => $sourceEntry) {
        if (isset($sourceEntry['password'])) {
            $config['sources'][$sourceIndex]['password'] = ($sourceEntry['password'] !== '') ? '********' : '';
        }
    }
    admin_ok(['config' => $config, 'version' => $row['version'] ?? 0]);
}

if ($action === 'etl_save') {
    require_not_demo('Demo mode — writes disabled.');
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        admin_err('Invalid JSON.');
    }
    require_once __DIR__ . '/../config_store.php';
    require_once __DIR__ . '/../etl_engine.php';
    $existing = etl_migrate_legacy_connection(config_get('etl') ?: []);

    $existingPassById = [];
    foreach ((array)($existing['sources'] ?? []) as $existingSource) {
        if (is_array($existingSource) && ($existingSource['id'] ?? '') !== '') {
            $existingPassById[(string)$existingSource['id']] = (string)($existingSource['password'] ?? '');
        }
    }

    $validFrequencies = ['manual', 'daily', 'weekly', 'monthly'];
    $validDrivers      = array_keys(etl_source_drivers());

    $sources     = [];
    $validSourceIds = [];
    foreach ((array)($data['sources'] ?? []) as $sourceEntry) {
        if (!is_array($sourceEntry)) {
            continue;
        }
        $name = trim((string)($sourceEntry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $id     = trim((string)($sourceEntry['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }
        $driver = strtolower(trim((string)($sourceEntry['driver'] ?? 'mysql')));
        if (!in_array($driver, $validDrivers, true)) {
            $driver = 'mysql';
        }

        $newPass = (string)($sourceEntry['password'] ?? '');
        if ($newPass === '********') {
            $newPass = $existingPassById[$id] ?? '';
        }
        $protocol = strtolower(trim((string)($sourceEntry['protocol'] ?? 'ftp')));
        $csvDelim = (string)($sourceEntry['csv_delimiter'] ?? ',');
        $csvDelim = ($csvDelim !== '') ? substr($csvDelim, 0, 1) : ',';
        $sources[] = [
            'id'             => $id,
            'name'           => $name,
            'driver'         => $driver,
            'host'           => trim((string)($sourceEntry['host'] ?? '')),
            'port'           => (int)($sourceEntry['port'] ?? 0) ?: etl_source_drivers()[$driver],
            'database'       => trim((string)($sourceEntry['database'] ?? '')),
            'user'           => trim((string)($sourceEntry['user'] ?? '')),
            'password'       => $newPass,

            'protocol'       => in_array($protocol, ['ftp', 'ftps'], true) ? $protocol : 'ftp',
            'remote_dir'     => trim((string)($sourceEntry['remote_dir'] ?? '')),
            'file_name'      => trim((string)($sourceEntry['file_name'] ?? '')),
            'csv_delimiter'  => $csvDelim,
            'csv_has_header' => (bool)($sourceEntry['csv_has_header'] ?? true),
            'passive_mode'   => (bool)($sourceEntry['passive_mode'] ?? true),
        ];
        $validSourceIds[] = $id;
    }

    $config = [
        'enabled'   => (bool)($data['enabled'] ?? false),
        'frequency' => in_array($data['frequency'] ?? '', $validFrequencies, true) ? $data['frequency'] : 'daily',
        'sources'   => $sources,
        'jobs'      => [],
    ];

    $existingJobsById = [];
    foreach ((array)($existing['jobs'] ?? []) as $existingJob) {
        if (is_array($existingJob) && ($existingJob['id'] ?? '') !== '') {
            $existingJobsById[(string)$existingJob['id']] = $existingJob;
        }
    }

    $sourceDriverById = [];
    foreach ($sources as $sourceDefinition) {
        $sourceDriverById[$sourceDefinition['id']] = $sourceDefinition['driver'];
    }

    $validModes = ['full_refresh', 'append', 'upsert'];
    foreach ((array)($data['jobs'] ?? []) as $job) {
        if (!is_array($job)) {
            continue;
        }
        $name   = trim((string)($job['name'] ?? ''));
        $target = trim((string)($job['target_table'] ?? ''));
        $schema = trim((string)($job['target_schema'] ?? ''));
        if ($name === '' || $target === '' || $schema === '') {
            continue;
        }
        $id = trim((string)($job['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }

        $sourceId = trim((string)($job['source_id'] ?? ''));
        if (!in_array($sourceId, $validSourceIds, true)) {
            $sourceId = $validSourceIds[0] ?? '';
        }

        $isRemoteFileJob = etl_source_is_remote_file_driver($sourceDriverById[$sourceId] ?? '');
        $query = trim((string)($job['source_query'] ?? ''));
        if (!$isRemoteFileJob && $query === '') {
            continue;
        }

        $columnMap = [];
        foreach ((array)($job['column_map'] ?? []) as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }
            $sourceEntry = trim((string)($mapping['source'] ?? ''));
            $targetColumn = trim((string)($mapping['target'] ?? ''));
            if ($sourceEntry !== '' && $targetColumn !== '') {
                $columnMap[] = ['source' => $sourceEntry, 'target' => $targetColumn];
            }
        }

        $config['jobs'][] = [
            'id'                        => $id,
            'name'                      => $name,
            'source_id'                 => $sourceId,
            'source_query'              => $query,
            'target_schema'             => $schema,
            'target_table'              => $target,
            'load_mode'                 => in_array($job['load_mode'] ?? '', $validModes, true)
                ? $job['load_mode']
                : 'full_refresh',
            'upsert_key'                => array_values(array_filter(array_map(
                static fn($key) => trim((string)$key),
                (array)($job['upsert_key'] ?? [])
            ), static fn($key) => $key !== '')),
            'enabled'                   => (bool)($job['enabled'] ?? true),
            'batch_size'                => max(50, min(5000, (int)($job['batch_size'] ?? 500) ?: 500)),
            'incremental_column'        => trim((string)($job['incremental_column'] ?? '')),
            'incremental_initial_value' => trim((string)($job['incremental_initial_value'] ?? '')),
            'column_map'                => $columnMap,

            'last_watermark'            => $existingJobsById[$id]['last_watermark'] ?? null,
        ];
    }

    admin_config_save_versioned('etl', $config, admin_expected_version($data));
}

if ($action === 'etl_test_connection') {
    require_once __DIR__ . '/../etl_engine.php';
    require_once __DIR__ . '/../config_store.php';
    $data = json_decode((string) file_get_contents('php://input'), true);
    $conn = is_array($data['connection'] ?? null) ? $data['connection'] : [];

    if (($conn['password'] ?? '') === '********' || ($conn['password'] ?? '') === '') {
        $conn['password'] = etl_stored_source_password((string)($conn['id'] ?? ''));
    }

    if (etl_source_is_remote_file_driver(strtolower(trim((string)($conn['driver'] ?? ''))))) {
        $ftpConnection = etl_ftp_connect($conn, 'etl:test');
        if ($ftpConnection === null) {
            echo json_encode([
                'status' => 'error',
                'error'  => 'Could not connect — check host, port, protocol, directory, user and password.',
            ]);
            throw ResponseException::sent();
        }
        $fileName = trim((string)($conn['file_name'] ?? ''));
        $fileExists = ($fileName === '')
            || @ftp_size($ftpConnection, $fileName) !== -1
            || in_array($fileName, (array)@ftp_nlist($ftpConnection, '.'), true);
        if (!$fileExists) {
            @ftp_close($ftpConnection);
            echo json_encode([
                'status' => 'error',
                'error'  => 'Connected, but the file "' . $fileName . '" was not found in the configured directory.',
            ]);
            throw ResponseException::sent();
        }
        @ftp_close($ftpConnection);
        admin_ok(['message' => 'Connection OK.']);
    }

    $pdo = etl_source_pdo($conn, 'etl:test');
    if ($pdo === null) {
        echo json_encode([
            'status' => 'error',
            'error'  => 'Could not connect — check driver, host, database, user and password.',
        ]);
        throw ResponseException::sent();
    }
    admin_ok(['message' => 'Connection OK.']);
}

if ($action === 'etl_preview') {
    require_once __DIR__ . '/../etl_engine.php';
    require_once __DIR__ . '/../config_store.php';
    $data     = json_decode((string) file_get_contents('php://input'), true);
    $connIn   = is_array($data['connection'] ?? null) ? $data['connection'] : [];
    $query    = trim((string)($data['source_query'] ?? ''));
    if (($connIn['password'] ?? '') === '********' || ($connIn['password'] ?? '') === '') {
        $connIn['password'] = etl_stored_source_password((string)($connIn['id'] ?? ''));
    }

    if (etl_source_is_remote_file_driver(strtolower(trim((string)($connIn['driver'] ?? ''))))) {
        $rows = etl_fetch_csv_rows($connIn, 'etl:preview', 20);
        if ($rows === null) {
            echo json_encode([
                'status' => 'error',
                'error'  => 'Could not fetch/parse the source CSV file — check connection, path and file name.',
            ]);
            throw ResponseException::sent();
        }
        $columns = empty($rows) ? [] : array_keys($rows[0]);
        admin_ok(['columns' => $columns, 'rows' => $rows]);
    }

    if (($error = etl_validate_source_query($query)) !== null) {
        admin_err($error);
    }
    $pdo = etl_source_pdo($connIn, 'etl:preview');
    if ($pdo === null) {
        admin_err('Source connection is not configured or unavailable.');
    }
    try {
        $statement = $pdo->query($query);
        $rows = [];
        while (count($rows) < 20 && ($row = $statement->fetch()) !== false) {
            $rows[] = $row;
        }
        $statement->closeCursor();
    } catch (\PDOException $exception) {
        error_log('[etl][preview] ' . $exception->getMessage());
        admin_err('Preview query failed.');
    }
    $columns = empty($rows) ? [] : array_keys($rows[0]);
    admin_ok(['columns' => $columns, 'rows' => $rows]);
}

if ($action === 'etl_target_schemas') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $sql  = 'SELECT schema_name FROM information_schema.schemata '
            . "WHERE schema_name NOT IN ('pg_catalog', 'information_schema') "
            . "AND schema_name NOT LIKE 'pg\\_toast%' AND schema_name NOT LIKE 'pg\\_temp%' "
            . 'ORDER BY schema_name';
        $result = @pg_query($conn, $sql);
        if (!$result) {
            admin_db_fail($conn, 'etl_target_schemas');
        }
        $schemas = [];
        while ($row = pg_fetch_assoc($result)) {
            $schemas[] = $row['schema_name'];
        }
        echo json_encode(['status' => 'success', 'schemas' => $schemas]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'etl_target_tables') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn   = db_connect();
        $schema = trim(os_request()->query('schema'));
        if ($schema === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
            admin_err('Invalid schema.');
        }

        $result = @pg_query_params(
            $conn,
            "SELECT table_name FROM information_schema.tables "
                . "WHERE table_schema = $1 AND table_type = 'BASE TABLE' "
                . "AND table_name NOT LIKE 'spw\\_%' ESCAPE '\\' "
                . 'ORDER BY table_name',
            [$schema]
        );
        if (!$result) {
            admin_db_fail($conn, 'etl_target_tables');
        }
        $tables = [];
        while ($row = pg_fetch_assoc($result)) {
            $tables[] = $row['table_name'];
        }
        echo json_encode(['status' => 'success', 'tables' => $tables]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'run_etl') {
    require_not_demo('Demo mode — writes disabled.');
    require_once __DIR__ . '/etl_common.php';
    $data  = json_decode((string) file_get_contents('php://input'), true);
    $jobId = trim((string)($data['job_id'] ?? ''));
    etl_admin_run_cron_script(__DIR__ . '/../../cron/cron_etl.php', $jobId, 'ETL cron script not found.');
}

if ($action === 'etl_log') {
    admin_try(static function (): void {
        $conn = admin_conn();
        $etlLogTable = sys_table('etl_log');
        admin_require_log_table($conn, $etlLogTable);
        $result = @pg_query(
            $conn,
            "SELECT id, job_id, job_name, triggered_by, status, rows_read, rows_written, error_message,
                    started_at, finished_at,
                    EXTRACT(EPOCH FROM (COALESCE(finished_at, now()) - started_at)) AS duration_sec
             FROM {$etlLogTable} ORDER BY started_at DESC LIMIT 50"
        );
        if (!$result) {
            admin_db_fail($conn, 'etl_log');
        }
        admin_ok(['rows' => admin_fetch_all($result)]);
    });
}

if ($action === 'etl_purge_log') {
    require_not_demo('Demo mode — writes disabled.');
    admin_try(static fn() => admin_purge_log(sys_table('etl_log'), 90, 'etl_purge_log'));
}
