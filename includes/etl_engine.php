<?php

declare(strict_types=1);

// includes/etl_engine.php — ETL engine: extract rows from a MySQL source and load
// them into a PostgreSQL target table. Pure procedural helpers, reused by the admin
// preview action and the cron worker. Builds its own MySQL PDO from the "etl" config
// connection block (independent of the removed MySQL Gateway / MYSQL_* constants).
//
// Security: all SQL identifiers via pg_ident()/etl_bt(); all values as bound params.
// The source query is restricted to a single read-only SELECT.

require_once __DIR__ . '/db.php';

// Backtick-quote a MySQL identifier (strips embedded backticks).
function etl_bt(string $name): string
{
    return '`' . str_replace('`', '', $name) . '`';
}

/**
 * Build a MySQL PDO from an "etl" config connection block. Returns null when the
 * connection is not configured. Timeout-bounded and fail-safe.
 *
 * @param array<string,mixed> $conn host/port/database/user/password
 */
function etl_mysql_pdo(array $conn, string $logTag = 'etl'): ?\PDO
{
    $host = trim((string)($conn['host'] ?? ''));
    $db   = trim((string)($conn['database'] ?? ''));
    $user = trim((string)($conn['user'] ?? ''));
    if ($host === '' || $db === '' || $user === '') {
        return null;
    }
    $port    = (int)($conn['port'] ?? 3306) ?: 3306;
    $pass    = (string)($conn['password'] ?? '');
    $timeout = 5;
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=%d',
            $host,
            $port,
            $db,
            $timeout
        );
        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => $timeout,
        ]);
    } catch (\PDOException $e) {
        error_log('[' . $logTag . '][etl][mysql] ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
        return null;
    }
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
 * 'error' => ?string]. Never throws — failures are captured in the return value.
 *
 * @param array<string,mixed> $job      one entry from etl config "jobs"
 * @param array<string,mixed> $connCfg  the etl config "connection" block
 */
function etl_run_job(\PgSql\Connection $pgConn, array $job, array $connCfg, bool $dryRun = false): array
{
    $name       = (string)($job['name'] ?? ($job['id'] ?? 'job'));
    $sourceSql  = (string)($job['source_query'] ?? '');
    $target     = trim((string)($job['target_table'] ?? ''));
    $loadMode   = (string)($job['load_mode'] ?? 'full_refresh');
    $upsertKey  = array_values(array_filter(array_map(
        static fn($k) => trim((string)$k),
        (array)($job['upsert_key'] ?? [])
    ), static fn($k) => $k !== ''));

    $out = ['status' => 'error', 'rows_read' => 0, 'rows_written' => 0, 'error' => null];

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

    $pdo = etl_mysql_pdo($connCfg, 'etl:' . $name);
    if ($pdo === null) {
        $out['error'] = 'MySQL source connection is not configured or unavailable.';
        return $out;
    }

    // Extract.
    try {
        $stmt = $pdo->query($sourceSql);
        $rows = $stmt->fetchAll();
    } catch (\PDOException $e) {
        error_log('[etl][' . $name . '] extract failed: ' . $e->getMessage());
        $out['error'] = 'Source query failed.';
        return $out;
    }
    $out['rows_read'] = count($rows);

    $schema     = etl_target_schema($target);
    $targetCols = etl_pg_columns($pgConn, $schema, $target);
    if (empty($targetCols)) {
        $out['error'] = "Target table '{$schema}.{$target}' not found or has no columns.";
        return $out;
    }

    // Only load columns that exist in both the source result and the target table.
    $sourceCols = empty($rows) ? [] : array_keys($rows[0]);
    $cols       = array_values(array_intersect($sourceCols, $targetCols));
    if (empty($cols) && !empty($rows)) {
        $out['error'] = 'No source columns match the target table columns.';
        return $out;
    }
    if ($loadMode === 'upsert') {
        foreach ($upsertKey as $k) {
            if (!in_array($k, $cols, true)) {
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
    $colIdents   = array_map('pg_ident', $cols);
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

        // Batch INSERT in chunks to keep statement size and memory bounded.
        $chunkSize = 500;
        $onConflict = '';
        if ($loadMode === 'upsert') {
            $keyIdents = implode(', ', array_map('pg_ident', $upsertKey));
            $updateCols = array_values(array_diff($cols, $upsertKey));
            $setSql = empty($updateCols)
                ? implode(', ', array_map(fn($c) => pg_ident($c) . ' = EXCLUDED.' . pg_ident($c), $cols))
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
