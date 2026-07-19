<?php

declare(strict_types=1);

// includes/etl_engine.php — ETL engine: extract rows from an external source database
// (MySQL, PostgreSQL; extensible to Oracle/DB2/SQL Server) and load them into a local
// PostgreSQL target table. Pure procedural helpers, reused by the admin preview action
// and the cron worker. Builds its own source PDO from the "etl" config connection block
// (independent of the removed MySQL Gateway / MYSQL_* constants).
//
// Security: all target identifiers via pg_ident(); all values as bound params.
// The source query is restricted to a single read-only SELECT.

require_once __DIR__ . '/db.php';

// Supported source drivers → default port. Add oracle ('oci' 1521), db2 ('ibm' 50000),
// sqlserver ('sqlsrv' 1433) here (plus a DSN case in etl_source_pdo) when those PDO
// extensions are available in the target environment.
function etl_source_drivers(): array
{
    return ['mysql' => 3306, 'pgsql' => 5432];
}

// Retry policy for transient source-DB errors (connection drops, lock/timeout waits):
// 3 attempts total, sleeping between them — 1s, then 5s.
const ETL_RETRY_DELAYS = [1, 5];

/**
 * Whether a PDOException looks like a transient/retryable condition (network drop,
 * connection refused, lock wait timeout, deadlock) rather than a permanent one
 * (bad credentials, syntax error, unknown table/column).
 */
function etl_is_transient_pdo_error(\PDOException $e): bool
{
    $sqlstate = (string)($e->errorInfo[0] ?? substr((string)$e->getCode(), 0, 2));
    if (str_starts_with($sqlstate, '08')) { // SQLSTATE class 08 = connection exception
        return true;
    }
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    // MySQL: 2002/2003/2006/2013 connection drop, 1205 lock wait timeout, 1213 deadlock.
    // PostgreSQL: 57P03 cannot connect now, 53300 too many connections, 40001 serialization failure.
    return in_array($driverCode, [2002, 2003, 2006, 2013, 1205, 1213], true)
        || in_array($sqlstate, ['57P03', '53300', '40001'], true);
}

/**
 * Run $fn with retry-on-transient-error: up to count(ETL_RETRY_DELAYS)+1 attempts,
 * sleeping ETL_RETRY_DELAYS[$i] seconds between them. Re-throws the last exception
 * immediately on a non-transient error, or after the final attempt.
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
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
    // Unreachable — the loop always returns or throws.
    throw new \RuntimeException('etl_with_retry: exhausted attempts without result.');
}

/**
 * Build a source-database PDO from an "etl" config connection block, dispatching on
 * $conn['driver']. Returns null when the connection is not configured or the driver
 * is unsupported. Timeout-bounded and fail-safe. Retries transient connection errors
 * per ETL_RETRY_DELAYS.
 *
 * @param array<string,mixed> $conn driver/host/port/database/user/password
 */
function etl_source_pdo(array $conn, string $logTag = 'etl'): ?\PDO
{
    $driver = strtolower(trim((string)($conn['driver'] ?? 'mysql')));
    $host   = trim((string)($conn['host'] ?? ''));
    $db     = trim((string)($conn['database'] ?? ''));
    $user   = trim((string)($conn['user'] ?? ''));
    if ($host === '' || $db === '' || $user === '') {
        return null;
    }
    $defaultPorts = etl_source_drivers();
    if (!isset($defaultPorts[$driver])) {
        error_log('[' . $logTag . '][etl] Unsupported source driver: ' . $driver);
        return null;
    }
    $port    = (int)($conn['port'] ?? 0) ?: $defaultPorts[$driver];
    $pass    = (string)($conn['password'] ?? '');
    $timeout = 5;
    try {
        return etl_with_retry(static function () use ($driver, $host, $port, $db, $user, $pass, $timeout) {
            $dsn = match ($driver) {
                'mysql' => sprintf(
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

/**
 * Read the "etl" config row straight from the database, bypassing the per-request/APCu
 * cache in config_store.php. Needed by concurrent job runners (each a separate CLI
 * process) so a watermark-persist retry sees the other runner's just-committed write
 * instead of a stale cached copy.
 */
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

/**
 * Persist a job's new incremental watermark into the "etl" config, retrying on an
 * optimistic-lock conflict (concurrent job runners writing to the same config row).
 * Best-effort: logs and gives up after a few attempts rather than blocking a job run.
 */
function etl_persist_watermark(string $jobId, string $newWatermark, string $logTag): void
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $row = etl_reload_config_row();
        if ($row === null) {
            return;
        }
        $config = $row['value'];
        $found  = false;
        foreach ($config['jobs'] ?? [] as $i => $j) {
            if ((string)($j['id'] ?? '') === $jobId) {
                if ((string)($j['last_watermark'] ?? '') === $newWatermark) {
                    return; // another runner already advanced it to this value.
                }
                $config['jobs'][$i]['last_watermark'] = $newWatermark;
                $found = true;
                break;
            }
        }
        if (!$found) {
            return;
        }
        $save = config_save('etl', $config, $row['version'], null);
        if ($save['status'] === 'ok') {
            return;
        }
        if ($save['status'] !== 'conflict') {
            error_log('[' . $logTag . '][etl] could not persist watermark: ' . ($save['error'] ?? 'unknown'));
            return;
        }
        usleep(random_int(100000, 400000));
    }
    error_log('[' . $logTag . '][etl] could not persist watermark after retries (lock conflict).');
}

/**
 * Resolve a job's source connection config from the "sources" list by id.
 * Returns null when the source id is unset or no longer exists (e.g. the source
 * was deleted after the job was created).
 *
 * @param array<int,array<string,mixed>> $sources the etl config "sources" array
 */
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

/**
 * Validate that a source query is a single read-only SELECT. Returns an error
 * string, or null when the query is acceptable.
 */
function etl_validate_source_query(string $sql): ?string
{
    $trimmed = trim($sql);
    if ($trimmed === '') {
        return 'Source query is empty.';
    }
    // Reject statement batching — a single SELECT only.
    if (str_contains(rtrim($trimmed, "; \t\n\r"), ';')) {
        return 'Source query must be a single statement (no ";").';
    }
    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $trimmed)) {
        return 'Source query must start with SELECT (or WITH).';
    }
    // Block obvious DML/DDL keywords defensively.
    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE|GRANT|CALL|INTO\s+OUTFILE)\b/i', $trimmed)) {
        return 'Source query must be read-only (no INSERT/UPDATE/DELETE/DDL).';
    }
    return null;
}

/**
 * Resolve the PostgreSQL schema for a target table from the "schema" config,
 * falling back to the system schema.
 */
function etl_target_schema(string $table): string
{
    $cfg = config_get('schema');
    $s   = $cfg['tables'][$table]['schema'] ?? '';
    return ($s !== '') ? (string)$s : sys_schema();
}

/**
 * Return the real column names of a PostgreSQL table (excludes generated columns).
 *
 * @return list<string>
 */
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
    while ($r = pg_fetch_assoc($res)) {
        $cols[] = $r['column_name'];
    }
    pg_free_result($res);
    return $cols;
}

/**
 * Run one ETL job: extract via the source query and load into the target table.
 * Returns ['status' => 'success'|'error', 'rows_read' => int, 'rows_written' => int,
 * 'error' => ?string, 'new_watermark' => ?string]. Never throws — failures are
 * captured in the return value.
 *
 * @param array<string,mixed> $job       one entry from etl config "jobs"
 * @param array<string,mixed> $connCfg   the etl config "connection" block
 * @param ?string             $watermark current incremental watermark value (for
 *                                        substitution into the {{watermark}} placeholder)
 */
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

    if (($err = etl_validate_source_query($sourceSql)) !== null) {
        $out['error'] = $err;
        return $out;
    }
    if ($target === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $target)) {
        $out['error'] = 'Invalid or missing target table.';
        return $out;
    }
    if (!in_array($loadMode, ['full_refresh', 'append', 'upsert'], true)) {
        $out['error'] = 'Invalid load mode.';
        return $out;
    }

    $pdo = etl_source_pdo($connCfg, 'etl:' . $name);
    if ($pdo === null) {
        $out['error'] = 'Source connection is not configured or unavailable.';
        return $out;
    }

    // Incremental: substitute the {{watermark}} placeholder with the last-seen value,
    // quoted for the source driver. Falls back to incremental_initial_value, then '0'.
    if ($incCol !== '' && str_contains($sourceSql, '{{watermark}}')) {
        $wm = $watermark ?? (string)($job['incremental_initial_value'] ?? '0');
        $sourceSql = str_replace('{{watermark}}', $pdo->quote($wm), $sourceSql);
    }

    // Extract. Reconnects and retries on a transient error (dropped connection, lock
    // wait timeout, deadlock); a permanent error (bad SQL, unknown column) fails immediately.
    $attempts = count(ETL_RETRY_DELAYS) + 1;
    $rows     = null;
    for ($i = 0; $i < $attempts; $i++) {
        try {
            $stmt = $pdo->query($sourceSql);
            $rows = $stmt->fetchAll();
            break;
        } catch (\PDOException $e) {
            $isLast = ($i === $attempts - 1);
            if ($isLast || !etl_is_transient_pdo_error($e)) {
                error_log('[etl][' . $name . '] extract failed: ' . $e->getMessage());
                $out['error'] = 'Source query failed.';
                return $out;
            }
            $delay = ETL_RETRY_DELAYS[$i];
            error_log('[etl][' . $name . '] extract transient error (attempt ' . ($i + 1) . '/' . $attempts . '): '
                . $e->getMessage() . ' — retrying in ' . $delay . 's');
            sleep($delay);
            $pdo = etl_source_pdo($connCfg, 'etl:' . $name);
            if ($pdo === null) {
                $out['error'] = 'Source connection is not configured or unavailable.';
                return $out;
            }
        }
    }
    $out['rows_read'] = count($rows);

    if ($incCol !== '' && !empty($rows)) {
        $max = null;
        foreach ($rows as $r) {
            $v = $r[$incCol] ?? null;
            if ($v !== null && ($max === null || (string)$v > (string)$max)) {
                $max = $v;
            }
        }
        if ($max !== null) {
            $out['new_watermark'] = (string)$max;
        }
    }

    $schema     = etl_target_schema($target);
    $targetCols = etl_pg_columns($pgConn, $schema, $target);
    if (empty($targetCols)) {
        $out['error'] = "Target table '{$schema}.{$target}' not found or has no columns.";
        return $out;
    }

    // Column mapping: explicit source→target pairs when configured, else match by name.
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
    $cols = array_keys($pairs); // source-side keys used to read row values
    if (empty($cols) && !empty($rows)) {
        $out['error'] = 'No source columns map to the target table columns.';
        return $out;
    }
    // Upsert key names are target-side column names.
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
    $written     = 0;

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

        // Batch INSERT in chunks to keep statement size and memory bounded (configurable per job).
        $chunkSize = $batchSize;
        $onConflict = '';
        if ($loadMode === 'upsert') {
            $keyIdents = implode(', ', array_map('pg_ident', $upsertKey));
            $updateCols = array_values(array_diff($targetNames, $upsertKey));
            $setSql = empty($updateCols)
                ? implode(', ', array_map(fn($c) => pg_ident($c) . ' = EXCLUDED.' . pg_ident($c), $targetNames))
                : implode(', ', array_map(fn($c) => pg_ident($c) . ' = EXCLUDED.' . pg_ident($c), $updateCols));
            $onConflict = " ON CONFLICT ({$keyIdents}) DO UPDATE SET {$setSql}";
        }

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $params    = [];
            $valueSql  = [];
            $ph        = 1;
            foreach ($chunk as $row) {
                $slots = [];
                foreach ($cols as $c) {
                    $val      = $row[$c] ?? null;
                    $params[] = ($val === '') ? null : $val;
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
