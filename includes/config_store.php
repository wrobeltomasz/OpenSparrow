<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function config_store_conn(): ?\PgSql\Connection
{
    static $conn = null;
    static $failed = false;

    if ($conn !== null && @pg_connection_status($conn) !== PGSQL_CONNECTION_OK) {
        $conn = null;
    }
    if ($conn === null && !$failed) {
        try {
            $conn = db_connect();
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $e) {
            $failed = true;
        }
    }
    return $conn;
}

function config_valid_key(string $key): bool
{
    return preg_match('/^[a-z0-9_]{1,64}$/', $key) === 1;
}

const CONFIG_CACHE_ABSENT = ['spw_absent' => true];

function config_cache(string $key, ?array $row = null, bool $write = false, int $ttl = 300): ?array
{
    static $cache = [];
    $apcuKey = 'spw_cfg:' . sys_schema() . ':' . $key;
    if ($write) {
        $cache[$key] = $row;
        if ($row === null) {
            if (function_exists('apcu_delete')) {
                apcu_delete($apcuKey);
            }
        } elseif (function_exists('apcu_store')) {
            apcu_store($apcuKey, $row, $ttl);
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

function config_get_row(string $key, int $absentTtl = 0): ?array
{
    if (!config_valid_key($key)) {
        return null;
    }
    $cached = config_cache($key);
    if ($cached !== null) {
        return $cached === CONFIG_CACHE_ABSENT ? null : $cached;
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
            if ($absentTtl > 0) {
                config_cache($key, CONFIG_CACHE_ABSENT, true, $absentTtl);
            }
        }
    }
    return null;
}

function config_get(string $key, int $absentTtl = 0): ?array
{
    $row = config_get_row($key, $absentTtl);
    return $row['value'] ?? null;
}

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
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        @pg_query($conn, 'ROLLBACK');
        error_log('[config_store] ' . $e->getMessage());
        return ['status' => 'error', 'error' => 'Database error'];
    }

    config_cache($key, ['value' => $data, 'version' => $newVersion], true);
    return ['status' => 'ok', 'version' => $newVersion];
}

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
