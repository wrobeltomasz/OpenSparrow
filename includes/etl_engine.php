<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;

require_once __DIR__ . '/db.php';

function etl_source_drivers(): array
{
    return ['mysql' => 3306, 'mariadb' => 3306, 'pgsql' => 5432, 'sqlite' => 0, 'csv_ftp' => 21];
}

function etl_source_is_file_driver(string $driver): bool
{
    return $driver === 'sqlite';
}

function etl_source_is_remote_file_driver(string $driver): bool
{
    return $driver === 'csv_ftp';
}

function etl_ftp_connect(array $conn, string $logTag = 'etl')
{
    if (!function_exists('ftp_connect')) {
        error_log('[' . $logTag . '][etl][csv_ftp] PHP ext-ftp is not available on this server.');
        return null;
    }
    $host     = trim((string)($conn['host'] ?? ''));
    $user     = trim((string)($conn['user'] ?? ''));
    if ($host === '' || $user === '') {
        return null;
    }
    $port     = (int)($conn['port'] ?? 0) ?: 21;
    $pass     = (string)($conn['password'] ?? '');
    $protocol = strtolower(trim((string)($conn['protocol'] ?? 'ftp')));
    $timeout  = 10;

    $ftpConnection = ($protocol === 'ftps' && function_exists('ftp_ssl_connect'))
        ? @ftp_ssl_connect($host, $port, $timeout)
        : @ftp_connect($host, $port, $timeout);
    if ($ftpConnection === false) {
        error_log('[' . $logTag . '][etl][csv_ftp] Could not connect to ' . $host . ':' . $port);
        return null;
    }
    if (!@ftp_login($ftpConnection, $user, $pass)) {
        error_log('[' . $logTag . '][etl][csv_ftp] Login failed for user ' . $user);
        @ftp_close($ftpConnection);
        return null;
    }
    @ftp_pasv($ftpConnection, ($conn['passive_mode'] ?? true) !== false);

    $remoteDir = trim((string)($conn['remote_dir'] ?? ''));
    if ($remoteDir !== '' && !@ftp_chdir($ftpConnection, $remoteDir)) {
        error_log('[' . $logTag . '][etl][csv_ftp] Could not change to directory ' . $remoteDir);
        @ftp_close($ftpConnection);
        return null;
    }
    return $ftpConnection;
}

function etl_fetch_csv_rows(array $conn, string $logTag = 'etl', ?int $limit = null): ?array
{
    $fileName = trim((string)($conn['file_name'] ?? ''));
    if ($fileName === '') {
        error_log('[' . $logTag . '][etl][csv_ftp] No file name configured.');
        return null;
    }
    $ftpConnection = etl_ftp_connect($conn, $logTag);
    if ($ftpConnection === null) {
        return null;
    }
    $tempFile = tempnam(sys_get_temp_dir(), 'etl_csv_');
    if ($tempFile === false || !@ftp_get($ftpConnection, $tempFile, $fileName, FTP_BINARY)) {
        error_log('[' . $logTag . '][etl][csv_ftp] Could not download file ' . $fileName);
        @ftp_close($ftpConnection);
        if ($tempFile !== false) {
            @unlink($tempFile);
        }
        return null;
    }
    @ftp_close($ftpConnection);

    $delimiter = (string)($conn['csv_delimiter'] ?? ',');
    $delimiter = ($delimiter !== '') ? $delimiter[0] : ',';
    $hasHeader = ($conn['csv_has_header'] ?? true) !== false;

    $handle = @fopen($tempFile, 'r');
    if ($handle === false) {
        @unlink($tempFile);
        return null;
    }
    $rows   = [];
    $header = null;
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($header === null) {
            if ($hasHeader) {
                $header = array_map(static fn($headerCell) => trim((string)$headerCell), $data);
                continue;
            }
            $header = array_map('strval', array_keys($data));
        }
        $row = [];
        foreach ($header as $columnIndex => $columnName) {
            $row[$columnName] = $data[$columnIndex] ?? null;
        }
        $rows[] = $row;
        if ($limit !== null && count($rows) >= $limit) {
            break;
        }
    }
    fclose($handle);
    @unlink($tempFile);
    return $rows;
}

const ETL_RETRY_DELAYS = [1, 5];

function etl_is_transient_pdo_error(\PDOException $exception): bool
{
    $sqlstate = (string)($exception->errorInfo[0] ?? substr((string)$exception->getCode(), 0, 2));
    if (str_starts_with($sqlstate, '08')) {
        return true;
    }
    $driverCode = (int)($exception->errorInfo[1] ?? 0);

    return in_array($driverCode, [2002, 2003, 2006, 2013, 1205, 1213], true)
        || in_array($sqlstate, ['57P03', '53300', '40001'], true);
}

function etl_with_retry(callable $callback, string $logTag)
{
    $attempts = count(ETL_RETRY_DELAYS) + 1;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        try {
            return $callback();
        } catch (\PDOException $exception) {
            $isLast = ($attempt === $attempts - 1);
            if ($isLast || !etl_is_transient_pdo_error($exception)) {
                throw $exception;
            }
            $delay = ETL_RETRY_DELAYS[$attempt];
            error_log('[' . $logTag . '][etl] transient error (attempt ' . ($attempt + 1) . '/' . $attempts . '): '
                . $exception->getMessage() . ' — retrying in ' . $delay . 's');
            sleep($delay);
        }
    }

    throw new \RuntimeException('etl_with_retry: exhausted attempts without result.');
}

function etl_source_pdo(array $conn, string $logTag = 'etl'): ?\PDO
{
    $driver = strtolower(trim((string)($conn['driver'] ?? 'mysql')));
    $databaseName     = trim((string)($conn['database'] ?? ''));
    if (!isset(etl_source_drivers()[$driver])) {
        error_log('[' . $logTag . '][etl] Unsupported source driver: ' . $driver);
        return null;
    }

    if (etl_source_is_file_driver($driver)) {
        if ($databaseName === '' || !is_readable($databaseName)) {
            return null;
        }
        try {
            return new \PDO('sqlite:' . $databaseName, null, null, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $exception) {
            error_log('[' . $logTag . '][etl][sqlite] ' . $exception->getMessage());
            return null;
        }
    }

    $host = trim((string)($conn['host'] ?? ''));
    $user = trim((string)($conn['user'] ?? ''));
    if ($host === '' || $databaseName === '' || $user === '') {
        return null;
    }
    $port    = (int)($conn['port'] ?? 0) ?: etl_source_drivers()[$driver];
    $pass    = (string)($conn['password'] ?? '');
    $timeout = 5;
    try {
        return etl_with_retry(static function () use ($driver, $host, $port, $databaseName, $user, $pass, $timeout) {
            $dsn = match ($driver) {
                'mysql', 'mariadb' => sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=%d',
                    $host,
                    $port,
                    $databaseName,
                    $timeout
                ),
                'pgsql' => sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
                    $host,
                    $port,
                    $databaseName,
                    $timeout
                ),
            };
            return new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT            => $timeout,
            ]);
        }, $logTag . ':' . $driver);
    } catch (\PDOException $exception) {
        error_log('[' . $logTag . '][etl][' . $driver . '] ' . $exception->getMessage()
            . ' | ' . $exception->getTraceAsString());
        return null;
    }
}

function etl_reload_config_row(): ?array
{
    $conn = config_store_conn();
    if ($conn === null) {
        return null;
    }
    $configTable = sys_table('config');
    $result     = @pg_query_params($conn, "SELECT value, version FROM {$configTable} WHERE config_key = \$1", ['etl']);
    if ($result === false) {
        return null;
    }
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    if (!$row) {
        return null;
    }
    $decoded = json_decode((string)$row['value'], true);
    return is_array($decoded) ? ['value' => $decoded, 'version' => (int)$row['version']] : null;
}

function etl_config_optimistic_update(
    string $key,
    callable $mutator,
    string $logTag,
    ?callable $reader = null
): void {
    $reader ??= static fn(): ?array => config_get_row($key);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $row = $reader();
        if (!is_array($row)) {
            return;
        }
        $config = $row['value'];
        if ($mutator($config) === false) {
            return;
        }
        $save = config_save($key, $config, $row['version'], null);
        if ($save['status'] === 'ok') {
            return;
        }
        if ($save['status'] !== 'conflict') {
            error_log('[' . $logTag . '] could not persist config: ' . ($save['error'] ?? 'unknown'));
            return;
        }
        usleep(random_int(100000, 400000));
    }
    error_log('[' . $logTag . '] could not persist config after retries (lock conflict).');
}

function etl_persist_watermark(string $jobId, string $newWatermark, string $logTag): void
{
    etl_config_optimistic_update('etl', static function (array &$config) use ($jobId, $newWatermark) {
        foreach ($config['jobs'] ?? [] as $jobIndex => $candidateJob) {
            if ((string)($candidateJob['id'] ?? '') === $jobId) {
                if ((string)($candidateJob['last_watermark'] ?? '') === $newWatermark) {
                    return false;
                }
                $config['jobs'][$jobIndex]['last_watermark'] = $newWatermark;
                return true;
            }
        }
        return false;
    }, $logTag . '[etl]', 'etl_reload_config_row');
}

function etl_resolve_source(array $sources, string $sourceId): ?array
{
    if ($sourceId === '') {
        return null;
    }
    foreach ($sources as $source) {
        if (is_array($source) && (string)($source['id'] ?? '') === $sourceId) {
            return $source;
        }
    }
    return null;
}

function etl_validate_source_query(string $sql): ?string
{
    $trimmed = trim($sql);
    if ($trimmed === '') {
        return 'Source query is empty.';
    }

    if (str_contains(rtrim($trimmed, "; \t\n\r"), ';')) {
        return 'Source query must be a single statement (no ";").';
    }
    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $trimmed)) {
        return 'Source query must start with SELECT (or WITH).';
    }

    if (preg_match('/(?:^|\(|;)\s*(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|CALL|MERGE)\b/i', $trimmed)) {
        return 'Source query must be read-only (no INSERT/UPDATE/DELETE/DDL).';
    }
    if (preg_match('/\b(REPLACE\s+INTO|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $trimmed)) {
        return 'Source query must be read-only (no writes to disk/table).';
    }
    return null;
}

function etl_watermark_gt(mixed $first, mixed $second): bool
{
    if (is_numeric($first) && is_numeric($second)) {
        return (float)$first > (float)$second;
    }
    return (string)$first > (string)$second;
}

function etl_pg_text_columns(\PgSql\Connection $conn, string $schema, string $table): array
{
    $result = @pg_query_params(
        $conn,
        "SELECT column_name FROM information_schema.columns "
        . "WHERE table_schema = \$1 AND table_name = \$2 "
        . "AND data_type IN ('character varying', 'varchar', 'character', 'char', 'text', 'name', 'citext')",
        [$schema, $table]
    );
    if (!$result) {
        return [];
    }
    $columns = [];
    while ($row = pg_fetch_assoc($result)) {
        $columns[] = $row['column_name'];
    }
    pg_free_result($result);
    return $columns;
}

function etl_pg_columns(\PgSql\Connection $conn, string $schema, string $table): array
{
    $result = @pg_query_params(
        $conn,
        'SELECT column_name FROM information_schema.columns '
        . 'WHERE table_schema = $1 AND table_name = $2 ORDER BY ordinal_position',
        [$schema, $table]
    );
    if (!$result) {
        return [];
    }
    $columns = [];
    while ($row = pg_fetch_assoc($result)) {
        $columns[] = $row['column_name'];
    }
    pg_free_result($result);
    return $columns;
}

function etl_run_job(
    \PgSql\Connection $pgConn,
    array $job,
    array $connCfg,
    bool $dryRun = false,
    ?string $watermark = null
): array {
    $name       = (string)($job['name'] ?? ($job['id'] ?? 'job'));
    $sourceSql  = (string)($job['source_query'] ?? '');
    $target     = trim((string)($job['target_table'] ?? ''));
    $schema     = trim((string)($job['target_schema'] ?? '')) ?: sys_schema();
    $loadMode   = (string)($job['load_mode'] ?? 'full_refresh');
    $upsertKey  = array_values(array_filter(array_map(
        static fn($upsertColumn) => trim((string)$upsertColumn),
        (array)($job['upsert_key'] ?? [])
    ), static fn($upsertColumn) => $upsertColumn !== ''));
    $incCol     = trim((string)($job['incremental_column'] ?? ''));
    $batchSize  = max(50, min(5000, (int)($job['batch_size'] ?? 500) ?: 500));
    $colMap     = [];
    foreach ((array)($job['column_map'] ?? []) as $mapping) {
        if (!is_array($mapping)) {
            continue;
        }
        $source = trim((string)($mapping['source'] ?? ''));
        $targetColumn = trim((string)($mapping['target'] ?? ''));
        if ($source !== '' && $targetColumn !== '') {
            $colMap[$source] = $targetColumn;
        }
    }

    $output = ['status' => 'error', 'rows_read' => 0, 'rows_written' => 0, 'error' => null, 'new_watermark' => null];

    $driver       = strtolower(trim((string)($connCfg['driver'] ?? 'mysql')));
    $isRemoteFile = etl_source_is_remote_file_driver($driver);

    if (!$isRemoteFile && ($error = etl_validate_source_query($sourceSql)) !== null) {
        $output['error'] = $error;
        return $output;
    }
    if ($target === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $target)) {
        $output['error'] = 'Invalid or missing target table.';
        return $output;
    }
    if ($schema === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
        $output['error'] = 'Invalid or missing target schema.';
        return $output;
    }
    if (!in_array($loadMode, ['full_refresh', 'append', 'upsert'], true)) {
        $output['error'] = 'Invalid load mode.';
        return $output;
    }

    if ($isRemoteFile) {
        $rows = etl_fetch_csv_rows($connCfg, 'etl:' . $name);
        if ($rows === null) {
            $output['error'] = 'Could not fetch/parse the source CSV file — check connection, path and file name.';
            return $output;
        }
    } else {
        $pdo = etl_source_pdo($connCfg, 'etl:' . $name);
        if ($pdo === null) {
            $output['error'] = 'Source connection is not configured or unavailable.';
            return $output;
        }

        if ($incCol !== '' && str_contains($sourceSql, '{{watermark}}')) {
            $watermarkValue = $watermark ?? (string)($job['incremental_initial_value'] ?? '0');
            $sourceSql = str_replace('{{watermark}}', $pdo->quote($watermarkValue), $sourceSql);
        }

        try {
            $rows = etl_with_retry(static function () use (&$pdo, $connCfg, $name, $sourceSql) {
                if ($pdo === null) {
                    $pdo = etl_source_pdo($connCfg, 'etl:' . $name);
                }
                if ($pdo === null) {
                    throw new \RuntimeException('Source connection is not configured or unavailable.');
                }
                try {
                    return $pdo->query($sourceSql)->fetchAll();
                } catch (\PDOException $exception) {
                    $pdo = null;
                    throw $exception;
                }
            }, 'etl:' . $name);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (\Throwable $exception) {
            error_log('[etl][' . $name . '] extract failed: ' . $exception->getMessage());
            $output['error'] = 'Source query failed.';
            return $output;
        }
    }
    $output['rows_read'] = count($rows);

    if ($incCol !== '' && !empty($rows)) {
        $max = null;
        foreach ($rows as $row) {
            $incrementalValue = $row[$incCol] ?? null;
            if ($incrementalValue !== null && ($max === null || etl_watermark_gt($incrementalValue, $max))) {
                $max = $incrementalValue;
            }
        }
        if ($max !== null) {
            $output['new_watermark'] = (string)$max;
        }
    }

    $targetCols = etl_pg_columns($pgConn, $schema, $target);
    if (empty($targetCols)) {
        $output['error'] = "Target table '{$schema}.{$target}' not found or has no columns.";
        return $output;
    }

    $sourceCols = empty($rows) ? [] : array_keys($rows[0]);
    if (!empty($colMap)) {
        $pairs = [];
        foreach ($colMap as $source => $targetColumn) {
            if (in_array($source, $sourceCols, true) && in_array($targetColumn, $targetCols, true)) {
                $pairs[$source] = $targetColumn;
            }
        }
    } else {
        $matched = array_values(array_intersect($sourceCols, $targetCols));
        $pairs   = array_combine($matched, $matched) ?: [];
    }
    $columns = array_keys($pairs);
    if (empty($columns) && !empty($rows)) {
        $output['error'] = 'No source columns map to the target table columns.';
        return $output;
    }

    $targetNames = array_values($pairs);
    if ($loadMode === 'upsert') {
        foreach ($upsertKey as $upsertColumn) {
            if (!in_array($upsertColumn, $targetNames, true)) {
                $output['error'] = "Upsert key column '{$upsertColumn}' is not among the loaded columns.";
                return $output;
            }
        }
        if (empty($upsertKey)) {
            $output['error'] = 'Upsert mode requires at least one key column.';
            return $output;
        }
    }

    if ($dryRun) {
        $output['status'] = 'success';
        return $output;
    }

    $schemaIdent = pg_ident($schema);
    $tableIdent  = pg_ident($target);
    $colIdents   = array_map('pg_ident', $targetNames);
    $textCols    = etl_pg_text_columns($pgConn, $schema, $target);
    $written     = 0;

    if ($loadMode === 'full_refresh' && empty($rows)) {
        error_log('[etl][' . $name . '] full_refresh skipped TRUNCATE: source returned 0 rows.');
        $output['status'] = 'success';
        return $output;
    }

    if (!@pg_query($pgConn, 'BEGIN')) {
        $output['error'] = 'Could not start transaction.';
        return $output;
    }
    try {
        if ($loadMode === 'full_refresh') {
            if (!@pg_query($pgConn, "TRUNCATE {$schemaIdent}.{$tableIdent}")) {
                throw new \RuntimeException('TRUNCATE failed: ' . pg_last_error($pgConn));
            }
        }

        $chunkSize = $batchSize;
        $onConflict = '';
        if ($loadMode === 'upsert') {
            $keyIdents = implode(', ', array_map('pg_ident', $upsertKey));
            $updateCols = array_values(array_diff($targetNames, $upsertKey));
            $setCols = empty($updateCols) ? $targetNames : $updateCols;
            $setSql  = implode(
                ', ',
                array_map(fn($column) => pg_ident($column) . ' = EXCLUDED.' . pg_ident($column), $setCols)
            );
            $onConflict = " ON CONFLICT ({$keyIdents}) DO UPDATE SET {$setSql}";
        }

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $params    = [];
            $valueSql  = [];
            $placeholderIndex        = 1;
            foreach ($chunk as $row) {
                $slots = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;

                    if ($value === '' && !in_array($pairs[$column], $textCols, true)) {
                        $value = null;
                    }
                    $params[] = $value;
                    $slots[]  = '$' . $placeholderIndex++;
                }
                $valueSql[] = '(' . implode(', ', $slots) . ')';
            }
            $sql = 'INSERT INTO ' . $schemaIdent . '.' . $tableIdent
                . ' (' . implode(', ', $colIdents) . ') VALUES '
                . implode(', ', $valueSql) . $onConflict;
            $result = @pg_query_params($pgConn, $sql, $params);
            if (!$result) {
                throw new \RuntimeException('INSERT failed: ' . pg_last_error($pgConn));
            }
            $written += count($chunk);
        }

        if (!@pg_query($pgConn, 'COMMIT')) {
            throw new \RuntimeException('COMMIT failed: ' . pg_last_error($pgConn));
        }
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (\Throwable $exception) {
        @pg_query($pgConn, 'ROLLBACK');
        error_log('[etl][' . $name . '] load failed: ' . $exception->getMessage());
        $output['error'] = 'Load failed — no partial data was written.';
        return $output;
    }

    $output['status']       = 'success';
    $output['rows_written'] = $written;
    return $output;
}
