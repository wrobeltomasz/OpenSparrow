<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

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

    $ftp = ($protocol === 'ftps' && function_exists('ftp_ssl_connect'))
        ? @ftp_ssl_connect($host, $port, $timeout)
        : @ftp_connect($host, $port, $timeout);
    if ($ftp === false) {
        error_log('[' . $logTag . '][etl][csv_ftp] Could not connect to ' . $host . ':' . $port);
        return null;
    }
    if (!@ftp_login($ftp, $user, $pass)) {
        error_log('[' . $logTag . '][etl][csv_ftp] Login failed for user ' . $user);
        @ftp_close($ftp);
        return null;
    }
    @ftp_pasv($ftp, ($conn['passive_mode'] ?? true) !== false);

    $remoteDir = trim((string)($conn['remote_dir'] ?? ''));
    if ($remoteDir !== '' && !@ftp_chdir($ftp, $remoteDir)) {
        error_log('[' . $logTag . '][etl][csv_ftp] Could not change to directory ' . $remoteDir);
        @ftp_close($ftp);
        return null;
    }
    return $ftp;
}

function etl_fetch_csv_rows(array $conn, string $logTag = 'etl', ?int $limit = null): ?array
{
    $fileName = trim((string)($conn['file_name'] ?? ''));
    if ($fileName === '') {
        error_log('[' . $logTag . '][etl][csv_ftp] No file name configured.');
        return null;
    }
    $ftp = etl_ftp_connect($conn, $logTag);
    if ($ftp === null) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'etl_csv_');
    if ($tmp === false || !@ftp_get($ftp, $tmp, $fileName, FTP_BINARY)) {
        error_log('[' . $logTag . '][etl][csv_ftp] Could not download file ' . $fileName);
        @ftp_close($ftp);
        if ($tmp !== false) {
            @unlink($tmp);
        }
        return null;
    }
    @ftp_close($ftp);

    $delimiter = (string)($conn['csv_delimiter'] ?? ',');
    $delimiter = ($delimiter !== '') ? $delimiter[0] : ',';
    $hasHeader = ($conn['csv_has_header'] ?? true) !== false;

    $handle = @fopen($tmp, 'r');
    if ($handle === false) {
        @unlink($tmp);
        return null;
    }
    $rows   = [];
    $header = null;
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($header === null) {
            if ($hasHeader) {
                $header = array_map(static fn($h) => trim((string)$h), $data);
                continue;
            }
            $header = array_map('strval', array_keys($data));
        }
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = $data[$i] ?? null;
        }
        $rows[] = $row;
        if ($limit !== null && count($rows) >= $limit) {
            break;
        }
    }
    fclose($handle);
    @unlink($tmp);
    return $rows;
}

const ETL_RETRY_DELAYS = [1, 5];

function etl_is_transient_pdo_error(\PDOException $e): bool
{
    $sqlstate = (string)($e->errorInfo[0] ?? substr((string)$e->getCode(), 0, 2));
    if (str_starts_with($sqlstate, '08')) {
        return true;
    }
    $driverCode = (int)($e->errorInfo[1] ?? 0);

    return in_array($driverCode, [2002, 2003, 2006, 2013, 1205, 1213], true)
        || in_array($sqlstate, ['57P03', '53300', '40001'], true);
}

function etl_with_retry(callable $fn, string $logTag)
{
    $attempts = count(ETL_RETRY_DELAYS) + 1;
    for ($i = 0; $i < $attempts; $i++) {
        try {
            return $fn();
        } catch (\PDOException $e) {
            $isLast = ($i === $attempts - 1);
            if ($isLast || !etl_is_transient_pdo_error($e)) {
                throw $e;
            }
            $delay = ETL_RETRY_DELAYS[$i];
            error_log('[' . $logTag . '][etl] transient error (attempt ' . ($i + 1) . '/' . $attempts . '): '
                . $e->getMessage() . ' — retrying in ' . $delay . 's');
            sleep($delay);
        }
    }

    throw new \RuntimeException('etl_with_retry: exhausted attempts without result.');
}

function etl_source_pdo(array $conn, string $logTag = 'etl'): ?\PDO
{
    $driver = strtolower(trim((string)($conn['driver'] ?? 'mysql')));
    $db     = trim((string)($conn['database'] ?? ''));
    if (!isset(etl_source_drivers()[$driver])) {
        error_log('[' . $logTag . '][etl] Unsupported source driver: ' . $driver);
        return null;
    }

    if (etl_source_is_file_driver($driver)) {
        if ($db === '' || !is_readable($db)) {
            return null;
        }
        try {
            return new \PDO('sqlite:' . $db, null, null, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            error_log('[' . $logTag . '][etl][sqlite] ' . $e->getMessage());
            return null;
        }
    }

    $host = trim((string)($conn['host'] ?? ''));
    $user = trim((string)($conn['user'] ?? ''));
    if ($host === '' || $db === '' || $user === '') {
        return null;
    }
    $port    = (int)($conn['port'] ?? 0) ?: etl_source_drivers()[$driver];
    $pass    = (string)($conn['password'] ?? '');
    $timeout = 5;
    try {
        return etl_with_retry(static function () use ($driver, $host, $port, $db, $user, $pass, $timeout) {
            $dsn = match ($driver) {
                'mysql', 'mariadb' => sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=%d',
                    $host,
                    $port,
                    $db,
                    $timeout
                ),
                'pgsql' => sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
                    $host,
                    $port,
                    $db,
                    $timeout
                ),
            };
            return new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT            => $timeout,
            ]);
        }, $logTag . ':' . $driver);
    } catch (\PDOException $e) {
        error_log('[' . $logTag . '][etl][' . $driver . '] ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
        return null;
    }
}

function etl_reload_config_row(): ?array
{
    $conn = config_store_conn();
    if ($conn === null) {
        return null;
    }
    $tConfig = sys_table('config');
    $res     = @pg_query_params($conn, "SELECT value, version FROM {$tConfig} WHERE config_key = \$1", ['etl']);
    if ($res === false) {
        return null;
    }
    $row = pg_fetch_assoc($res);
    pg_free_result($res);
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
        foreach ($config['jobs'] ?? [] as $i => $j) {
            if ((string)($j['id'] ?? '') === $jobId) {
                if ((string)($j['last_watermark'] ?? '') === $newWatermark) {
                    return false;
                }
                $config['jobs'][$i]['last_watermark'] = $newWatermark;
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
    foreach ($sources as $src) {
        if (is_array($src) && (string)($src['id'] ?? '') === $sourceId) {
            return $src;
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

function etl_watermark_gt(mixed $a, mixed $b): bool
{
    if (is_numeric($a) && is_numeric($b)) {
        return (float)$a > (float)$b;
    }
    return (string)$a > (string)$b;
}

function etl_pg_text_columns(\PgSql\Connection $conn, string $schema, string $table): array
{
    $res = @pg_query_params(
        $conn,
        "SELECT column_name FROM information_schema.columns "
        . "WHERE table_schema = \$1 AND table_name = \$2 "
        . "AND data_type IN ('character varying', 'varchar', 'character', 'char', 'text', 'name', 'citext')",
        [$schema, $table]
    );
    if (!$res) {
        return [];
    }
    $cols = [];
    while ($row = pg_fetch_assoc($res)) {
        $cols[] = $row['column_name'];
    }
    pg_free_result($res);
    return $cols;
}

function etl_pg_columns(\PgSql\Connection $conn, string $schema, string $table): array
{
    $res = @pg_query_params(
        $conn,
        'SELECT column_name FROM information_schema.columns '
        . 'WHERE table_schema = $1 AND table_name = $2 ORDER BY ordinal_position',
        [$schema, $table]
    );
    if (!$res) {
        return [];
    }
    $cols = [];
    while ($row = pg_fetch_assoc($res)) {
        $cols[] = $row['column_name'];
    }
    pg_free_result($res);
    return $cols;
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
        static fn($k) => trim((string)$k),
        (array)($job['upsert_key'] ?? [])
    ), static fn($k) => $k !== ''));
    $incCol     = trim((string)($job['incremental_column'] ?? ''));
    $batchSize  = max(50, min(5000, (int)($job['batch_size'] ?? 500) ?: 500));
    $colMap     = [];
    foreach ((array)($job['column_map'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $src = trim((string)($m['source'] ?? ''));
        $tgt = trim((string)($m['target'] ?? ''));
        if ($src !== '' && $tgt !== '') {
            $colMap[$src] = $tgt;
        }
    }

    $out = ['status' => 'error', 'rows_read' => 0, 'rows_written' => 0, 'error' => null, 'new_watermark' => null];

    $driver       = strtolower(trim((string)($connCfg['driver'] ?? 'mysql')));
    $isRemoteFile = etl_source_is_remote_file_driver($driver);

    if (!$isRemoteFile && ($err = etl_validate_source_query($sourceSql)) !== null) {
        $out['error'] = $err;
        return $out;
    }
    if ($target === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $target)) {
        $out['error'] = 'Invalid or missing target table.';
        return $out;
    }
    if ($schema === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
        $out['error'] = 'Invalid or missing target schema.';
        return $out;
    }
    if (!in_array($loadMode, ['full_refresh', 'append', 'upsert'], true)) {
        $out['error'] = 'Invalid load mode.';
        return $out;
    }

    if ($isRemoteFile) {
        $rows = etl_fetch_csv_rows($connCfg, 'etl:' . $name);
        if ($rows === null) {
            $out['error'] = 'Could not fetch/parse the source CSV file — check connection, path and file name.';
            return $out;
        }
    } else {
        $pdo = etl_source_pdo($connCfg, 'etl:' . $name);
        if ($pdo === null) {
            $out['error'] = 'Source connection is not configured or unavailable.';
            return $out;
        }

        if ($incCol !== '' && str_contains($sourceSql, '{{watermark}}')) {
            $wm = $watermark ?? (string)($job['incremental_initial_value'] ?? '0');
            $sourceSql = str_replace('{{watermark}}', $pdo->quote($wm), $sourceSql);
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
                } catch (\PDOException $e) {
                    $pdo = null;
                    throw $e;
                }
            }, 'etl:' . $name);
        } catch (\Throwable $e) {
            error_log('[etl][' . $name . '] extract failed: ' . $e->getMessage());
            $out['error'] = 'Source query failed.';
            return $out;
        }
    }
    $out['rows_read'] = count($rows);

    if ($incCol !== '' && !empty($rows)) {
        $max = null;
        foreach ($rows as $row) {
            $v = $row[$incCol] ?? null;
            if ($v !== null && ($max === null || etl_watermark_gt($v, $max))) {
                $max = $v;
            }
        }
        if ($max !== null) {
            $out['new_watermark'] = (string)$max;
        }
    }

    $targetCols = etl_pg_columns($pgConn, $schema, $target);
    if (empty($targetCols)) {
        $out['error'] = "Target table '{$schema}.{$target}' not found or has no columns.";
        return $out;
    }

    $sourceCols = empty($rows) ? [] : array_keys($rows[0]);
    if (!empty($colMap)) {
        $pairs = [];
        foreach ($colMap as $src => $tgt) {
            if (in_array($src, $sourceCols, true) && in_array($tgt, $targetCols, true)) {
                $pairs[$src] = $tgt;
            }
        }
    } else {
        $matched = array_values(array_intersect($sourceCols, $targetCols));
        $pairs   = array_combine($matched, $matched) ?: [];
    }
    $cols = array_keys($pairs);
    if (empty($cols) && !empty($rows)) {
        $out['error'] = 'No source columns map to the target table columns.';
        return $out;
    }

    $targetNames = array_values($pairs);
    if ($loadMode === 'upsert') {
        foreach ($upsertKey as $k) {
            if (!in_array($k, $targetNames, true)) {
                $out['error'] = "Upsert key column '{$k}' is not among the loaded columns.";
                return $out;
            }
        }
        if (empty($upsertKey)) {
            $out['error'] = 'Upsert mode requires at least one key column.';
            return $out;
        }
    }

    if ($dryRun) {
        $out['status'] = 'success';
        return $out;
    }

    $schemaIdent = pg_ident($schema);
    $tableIdent  = pg_ident($target);
    $colIdents   = array_map('pg_ident', $targetNames);
    $textCols    = etl_pg_text_columns($pgConn, $schema, $target);
    $written     = 0;

    if ($loadMode === 'full_refresh' && empty($rows)) {
        error_log('[etl][' . $name . '] full_refresh skipped TRUNCATE: source returned 0 rows.');
        $out['status'] = 'success';
        return $out;
    }

    if (!@pg_query($pgConn, 'BEGIN')) {
        $out['error'] = 'Could not start transaction.';
        return $out;
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
            $ph        = 1;
            foreach ($chunk as $row) {
                $slots = [];
                foreach ($cols as $column) {
                    $val = $row[$column] ?? null;

                    if ($val === '' && !in_array($pairs[$column], $textCols, true)) {
                        $val = null;
                    }
                    $params[] = $val;
                    $slots[]  = '$' . $ph++;
                }
                $valueSql[] = '(' . implode(', ', $slots) . ')';
            }
            $sql = 'INSERT INTO ' . $schemaIdent . '.' . $tableIdent
                . ' (' . implode(', ', $colIdents) . ') VALUES '
                . implode(', ', $valueSql) . $onConflict;
            $res = @pg_query_params($pgConn, $sql, $params);
            if (!$res) {
                throw new \RuntimeException('INSERT failed: ' . pg_last_error($pgConn));
            }
            $written += count($chunk);
        }

        if (!@pg_query($pgConn, 'COMMIT')) {
            throw new \RuntimeException('COMMIT failed: ' . pg_last_error($pgConn));
        }
    } catch (\Throwable $e) {
        @pg_query($pgConn, 'ROLLBACK');
        error_log('[etl][' . $name . '] load failed: ' . $e->getMessage());
        $out['error'] = 'Load failed — no partial data was written.';
        return $out;
    }

    $out['status']       = 'success';
    $out['rows_written'] = $written;
    return $out;
}
