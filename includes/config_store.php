<?php

declare(strict_types=1);

// config_store.php — DB-backed configuration store (spw_config / spw_config_log)
// spw_config is the sole source of truth: 3.0 is the first shipped version, so no instance
// ever had file-based config to fall back to. config/database.json (and security.json) stay
// files because they are read before a database connection exists.
// config_get($key) returns the decoded config value; config_get_row($key) also returns
// the optimistic-lock version.
// config_save() is transactional: SELECT ... FOR UPDATE, version check, UPDATE/INSERT,
// audit row in spw_config_log. Caches per request (static) and in APCu when available.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Shared lazy connection for config reads/writes. Returns null when the
 * database is unreachable so callers can fall back to file-based config.
 */
function config_store_conn(): ?\PgSql\Connection
{
    static $conn = null;
    static $failed = false;
    // db_connect() hands out a shared connection; re-open when other code closed it.
    if ($conn !== null && @pg_connection_status($conn) !== PGSQL_CONNECTION_OK) {
        $conn = null;
    }
    if ($conn === null && !$failed) {
        try {
            $conn = db_connect();
        } catch (Throwable $e) {
            $failed = true;
        }
    }
    return $conn;
}

/**
 * Validate a config key (matches the config/{$key}.json file-name convention).
 */
function config_valid_key(string $key): bool
{
    return preg_match('/^[a-z0-9_]{1,64}$/', $key) === 1;
}

/**
 * Per-request + APCu cache access. $row is ['value' => array, 'version' => int]
 * or null (negative caching is request-scoped only — a key can appear at any time).
 */
function config_cache(string $key, ?array $row = null, bool $write = false): ?array
{
    static $cache = [];
    $apcuKey = 'spw_cfg:' . sys_schema() . ':' . $key;
    if ($write) {
        $cache[$key] = $row;
        // A missing key must not be cached across requests — it only means "not saved yet".
        if ($row === null) {
            if (function_exists('apcu_delete')) {
                apcu_delete($apcuKey);
            }
        } elseif (function_exists('apcu_store')) {
            apcu_store($apcuKey, $row, 300);
        }
        return $row;
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (function_exists('apcu_fetch')) {
        $hit = apcu_fetch($apcuKey, $ok);
        if ($ok && is_array($hit)) {
            $cache[$key] = $hit;
            return $hit;
        }
    }
    return null;
}

/**
 * Full row lookup. Returns ['value' => array, 'version' => int] or null when the
 * key has no row yet (callers treat that as "not configured" and use their defaults).
 */
function config_get_row(string $key): ?array
{
    if (!config_valid_key($key)) {
        return null;
    }
    $cached = config_cache($key);
    if ($cached !== null) {
        return $cached;
    }

    $conn = config_store_conn();
    if ($conn !== null) {
        $tConfig = sys_table('config');
        $res = @pg_query_params(
            $conn,
            "SELECT value, version FROM $tConfig WHERE config_key = \$1",
            [$key]
        );
        if ($res !== false) {
            $dbRow = pg_fetch_assoc($res);
            pg_free_result($res);
            if ($dbRow !== false && $dbRow !== null) {
                $decoded = json_decode((string) $dbRow['value'], true);
                if (is_array($decoded)) {
                    $row = ['value' => $decoded, 'version' => (int) $dbRow['version']];
                    return config_cache($key, $row, true);
                }
            }
        }
    }
    return null;
}

/**
 * Convenience accessor: decoded config value or null when absent.
 */
function config_get(string $key): ?array
{
    $row = config_get_row($key);
    return $row['value'] ?? null;
}

/**
 * Transactional save with optimistic locking and spw_config_log audit trail.
 * $expectedVersion null = last-write-wins (also used for first-time inserts);
 * a non-null version that no longer matches returns status "conflict".
 * Returns ['status' => 'ok', 'version' => int] | ['status' => 'conflict']
 *       | ['status' => 'error', 'error' => string].
 */
function config_save(string $key, array $data, ?int $expectedVersion = null, ?int $userId = null): array
{
    if (!config_valid_key($key)) {
        return ['status' => 'error', 'error' => 'Invalid config key'];
    }
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['status' => 'error', 'error' => 'Config not serializable'];
    }
    if (defined('CONFIG_FILE_MAX_BYTES') && strlen($json) > CONFIG_FILE_MAX_BYTES) {
        return ['status' => 'error', 'error' => 'Config too large'];
    }

    $conn = config_store_conn();
    if ($conn === null) {
        return ['status' => 'error', 'error' => 'Database unavailable'];
    }
    $tConfig = sys_table('config');
    $tLog    = sys_table('config_log');

    if (!@pg_query($conn, 'BEGIN')) {
        return ['status' => 'error', 'error' => 'Database error'];
    }
    try {
        $res = @pg_query_params(
            $conn,
            "SELECT value, version FROM $tConfig WHERE config_key = \$1 FOR UPDATE",
            [$key]
        );
        if ($res === false) {
            throw new RuntimeException('config_save: select failed — ' . pg_last_error($conn));
        }
        $current = pg_fetch_assoc($res);
        pg_free_result($res);

        $oldJson = null;
        if ($current !== false && $current !== null) {
            if ($expectedVersion !== null && (int) $current['version'] !== $expectedVersion) {
                pg_query($conn, 'ROLLBACK');
                return ['status' => 'conflict'];
            }
            $oldJson    = (string) $current['value'];
            $newVersion = (int) $current['version'] + 1;
            $ok = @pg_query_params(
                $conn,
                "UPDATE $tConfig SET value = \$2::jsonb, version = \$3, updated_by = \$4, updated_at = now()
                 WHERE config_key = \$1",
                [$key, $json, $newVersion, $userId]
            );
        } else {
            if ($expectedVersion !== null && $expectedVersion !== 0) {
                // Caller expected an existing row (it was deleted concurrently).
                pg_query($conn, 'ROLLBACK');
                return ['status' => 'conflict'];
            }
            $newVersion = 1;
            $ok = @pg_query_params(
                $conn,
                "INSERT INTO $tConfig (config_key, value, version, updated_by) VALUES (\$1, \$2::jsonb, 1, \$3)",
                [$key, $json, $userId]
            );
        }
        if (!$ok) {
            throw new RuntimeException('config_save: write failed — ' . pg_last_error($conn));
        }

        $logOk = @pg_query_params(
            $conn,
            "INSERT INTO $tLog (config_key, old_value, new_value, changed_by)
             VALUES (\$1, \$2::jsonb, \$3::jsonb, \$4)",
            [$key, $oldJson, $json, $userId]
        );
        if (!$logOk) {
            throw new RuntimeException('config_save: log failed — ' . pg_last_error($conn));
        }
        if (!@pg_query($conn, 'COMMIT')) {
            throw new RuntimeException('config_save: commit failed — ' . pg_last_error($conn));
        }
    } catch (Throwable $e) {
        @pg_query($conn, 'ROLLBACK');
        error_log('[config_store] ' . $e->getMessage());
        return ['status' => 'error', 'error' => 'Database error'];
    }

    config_cache($key, ['value' => $data, 'version' => $newVersion], true);
    return ['status' => 'ok', 'version' => $newVersion];
}

/**
 * Delete a config key (audit-logged). Returns true when the row existed.
 */
function config_delete(string $key, ?int $userId = null): bool
{
    if (!config_valid_key($key)) {
        return false;
    }
    $conn = config_store_conn();
    if ($conn === null) {
        return false;
    }
    $tConfig = sys_table('config');
    $tLog    = sys_table('config_log');

    $res = @pg_query_params(
        $conn,
        "DELETE FROM $tConfig WHERE config_key = \$1 RETURNING value",
        [$key]
    );
    if ($res === false) {
        return false;
    }
    $deleted = pg_fetch_assoc($res);
    pg_free_result($res);
    config_cache($key, null, true);
    if ($deleted === false || $deleted === null) {
        return false;
    }
    @pg_query_params(
        $conn,
        "INSERT INTO $tLog (config_key, old_value, new_value, changed_by) VALUES (\$1, \$2::jsonb, NULL, \$3)",
        [$key, (string) $deleted['value'], $userId]
    );
    return true;
}
