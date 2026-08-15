<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

require_once __DIR__ . '/../api_helpers.php';

if ($action === 'overview') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $usersTable  = sys_table('users');
        $usersResult    = @pg_query(
            $conn,
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE is_active) AS active FROM {$usersTable}"
        );
        $usersRow    = $usersResult ? pg_fetch_assoc($usersResult) : ['total' => 0, 'active' => 0];

        require_once __DIR__ . '/../config_store.php';
        $schemaObj   = config_get('schema');
        $schemaTables = (is_array($schemaObj) && is_array($schemaObj['tables'] ?? null)) ? $schemaObj['tables'] : [];

        $tables   = [];
        $totalRec = 0;
        foreach ($schemaTables as $tableName => $tableDefinition) {
            $tableSchema = $tableDefinition['schema'] ?? 'public';
            $safeTable = sprintf('%s.%s', pg_ident($tableSchema), pg_ident((string) $tableName));
            $countResult  = @pg_query($conn, "SELECT COUNT(*) AS n FROM {$safeTable}");
            $count = $countResult ? (int) pg_fetch_result($countResult, 0, 0) : 0;
            $totalRec += $count;
            $tables[] = [
                'name'  => $tableName,
                'label' => $tableDefinition['display_name'] ?? $tableName,
                'count' => $count,
            ];
        }
        usort($tables, static fn($first, $second) => $second['count'] - $first['count']);

        $filesTable = sys_table('files');
        $filesResult   = @pg_query(
            $conn,
            "SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes),0) AS total_bytes"
            . " FROM {$filesTable} WHERE deleted_at IS NULL"
        );
        $filesRow   = $filesResult ? pg_fetch_assoc($filesResult) : ['n' => 0, 'total_bytes' => 0];

        $ragFilesTable   = sys_table('rag_files');
        $ragCountResult   = @pg_query($conn, "SELECT COUNT(*) AS n FROM {$ragFilesTable}");
        $ragCount = ($ragCountResult && pg_num_rows($ragCountResult) > 0)
            ? (int) pg_fetch_result($ragCountResult, 0, 0)
            : 0;

        require_once __DIR__ . '/../config_store.php';
        $viewsObj  = config_get('views');
        $viewCount = (is_array($viewsObj) && is_array($viewsObj['views'] ?? null)) ? count($viewsObj['views']) : 0;

        $autoCount = count(auto_cfg_read());

        $wfObj    = config_get('workflows');
        $wfCount  = (is_array($wfObj) && is_array($wfObj['workflows'] ?? null)) ? count($wfObj['workflows']) : 0;

        $etlObj    = config_get('etl');
        $etlCount  = (is_array($etlObj) && is_array($etlObj['jobs'] ?? null)) ? count($etlObj['jobs']) : 0;

        $printRow  = config_get_row('print');
        $printCfg  = $printRow['value'] ?? [];
        $printCount = (is_array($printCfg) && is_array($printCfg['prints'] ?? null)) ? count($printCfg['prints']) : 0;

        $anonRow   = config_get_row('anonymization');
        $anonCfg   = $anonRow['value'] ?? [];
        $anonCount = (is_array($anonCfg) && is_array($anonCfg['rules'] ?? null)) ? count($anonCfg['rules']) : 0;
        $anonEnabled = is_array($anonCfg) && !empty($anonCfg['enabled']);

        $cronLogTable = sys_table('users_notifications_log');
        $configLogResult  = @pg_query($conn, "
            SELECT TO_CHAR(started_at, 'YYYY-MM-DD HH24:MI') AS started_at,
                   status, triggered_by,
                   COALESCE(notifications_created, 0) AS sent
            FROM {$cronLogTable}
            ORDER BY started_at DESC
            LIMIT 5
        ");
        $cronRecent  = [];
        $lastCronRun = null;
        if ($configLogResult) {
            while ($row = pg_fetch_assoc($configLogResult)) {
                if ($lastCronRun === null) {
                    $lastCronRun = $row['started_at'];
                }
                $cronRecent[] = $row;
            }
        }

        $usersLogTable  = sys_table('users_log');
        $auditLogResult  = @pg_query($conn, "
            SELECT ul.action, ul.target_table,
                   TO_CHAR(ul.created_at, 'YYYY-MM-DD HH24:MI') AS created_at,
                   u.username
            FROM {$usersLogTable} ul
            LEFT JOIN {$usersTable} u ON u.id = ul.user_id
            ORDER BY ul.created_at DESC
            LIMIT 8
        ");
        $auditRecent = [];
        if ($auditLogResult) {
            while ($row = pg_fetch_assoc($auditLogResult)) {
                $auditRecent[] = $row;
            }
        }

        $databaseSizeResult  = @pg_query($conn, 'SELECT pg_database_size(current_database()) AS sz');
        $dbSizeBytes = ($databaseSizeResult) ? (int) pg_fetch_result($databaseSizeResult, 0, 0) : 0;

        $migrationsTable   = sys_table('migrations');
        $migrationsResult   = @pg_query($conn, "SELECT name FROM {$migrationsTable}");
        $applied = [];
        if ($migrationsResult) {
            while ($row = pg_fetch_row($migrationsResult)) {
                $applied[$row[0]] = true;
            }
        }

        $knownMigrations = [
            '3.0_baseline',
            '3.1_table_comments',
            '3.1_notes_reminder_time',
            '3.3_user_contact',
            '3.3_clickstats',
        ];
        $pendingMigrations = count(array_filter(
            $knownMigrations,
            static fn($migrationKey) => !isset($applied[$migrationKey])
        ));

        $versionFile  = __DIR__ . '/../../includes/VERSION';
        $appVersion   = file_exists($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown';
        $postgresVersionResult     = @pg_query($conn, 'SELECT version()');
        $pgVersionRaw = $postgresVersionResult ? (string) pg_fetch_result($postgresVersionResult, 0, 0) : '';
        $pgVersion    = '';
        if (preg_match('/PostgreSQL\s+([\d.]+)/i', $pgVersionRaw, $matches)) {
            $pgVersion = $matches[1];
        }
        $displayErrors = ini_get('display_errors');
        $memoryLimit   = ini_get('memory_limit');
        $uploadMaxFilesize     = ini_get('upload_max_filesize');
        $secureCookiesOk = defined('SECURE_COOKIES') ? (bool) SECURE_COOKIES : false;
        $ipHashSaltOk    = defined('IP_HASH_SALT') && IP_HASH_SALT !== '';
        $sessionLifetime = defined('SESSION_MAX_LIFETIME') ? (int) SESSION_MAX_LIFETIME : 0;

        echo json_encode([
            'status'            => 'success',
            'app_version'       => $appVersion,
            'user_total'        => (int) $usersRow['total'],
            'user_active'       => (int) $usersRow['active'],
            'table_count'       => count($tables),
            'tables'            => $tables,
            'total_records'     => $totalRec,
            'file_count'        => (int) $filesRow['n'],
            'file_size_bytes'   => (int) $filesRow['total_bytes'],
            'rag_count'         => $ragCount,
            'view_count'        => $viewCount,
            'automation_count'  => $autoCount,
            'workflow_count'    => $wfCount,
            'etl_job_count'     => $etlCount,
            'print_count'       => $printCount,
            'anonymization_rule_count' => $anonCount,
            'anonymization_enabled'    => $anonEnabled,
            'last_cron_run'     => $lastCronRun,
            'cron_recent'       => $cronRecent,
            'audit_recent'      => $auditRecent,
            'db_size_bytes'     => $dbSizeBytes,
            'pg_version'        => $pgVersion,
            'php_version'       => PHP_VERSION,
            'php_ok'            => version_compare(PHP_VERSION, '8.1.0', '>='),
            'display_errors_ok' => ($displayErrors === '' || $displayErrors == '0'
                || strtolower((string) $displayErrors) === 'off'),
            'pending_migrations' => $pendingMigrations,
            'memory_limit'       => $memoryLimit,
            'upload_max_filesize' => $uploadMaxFilesize,
            'secure_cookies_ok'  => $secureCookiesOk,
            'ip_hash_salt_ok'    => $ipHashSaltOk,
            'session_lifetime'   => $sessionLifetime,
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}
