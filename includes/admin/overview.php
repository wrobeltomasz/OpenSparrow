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

        $tUsers  = sys_table('users');
        $uRes    = @pg_query(
            $conn,
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE is_active) AS active FROM {$tUsers}"
        );
        $uRow    = $uRes ? pg_fetch_assoc($uRes) : ['total' => 0, 'active' => 0];

        require_once __DIR__ . '/../config_store.php';
        $schemaObj   = config_get('schema');
        $schemaTables = (is_array($schemaObj) && is_array($schemaObj['tables'] ?? null)) ? $schemaObj['tables'] : [];

        $tables   = [];
        $totalRec = 0;
        foreach ($schemaTables as $tableName => $tableDef) {
            $tableSchema = $tableDef['schema'] ?? 'public';
            $safeTable = sprintf('%s.%s', pg_ident($tableSchema), pg_ident((string) $tableName));
            $cRes  = @pg_query($conn, "SELECT COUNT(*) AS n FROM {$safeTable}");
            $count = $cRes ? (int) pg_fetch_result($cRes, 0, 0) : 0;
            $totalRec += $count;
            $tables[] = [
                'name'  => $tableName,
                'label' => $tableDef['display_name'] ?? $tableName,
                'count' => $count,
            ];
        }
        usort($tables, static fn($a, $b) => $b['count'] - $a['count']);

        $tFiles = sys_table('files');
        $fRes   = @pg_query(
            $conn,
            "SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes),0) AS total_bytes FROM {$tFiles} WHERE deleted_at IS NULL"
        );
        $fRow   = $fRes ? pg_fetch_assoc($fRes) : ['n' => 0, 'total_bytes' => 0];

        $tRag   = sys_table('rag_files');
        $rRes   = @pg_query($conn, "SELECT COUNT(*) AS n FROM {$tRag}");
        $ragCount = ($rRes && pg_num_rows($rRes) > 0) ? (int) pg_fetch_result($rRes, 0, 0) : 0;

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

        $tCronLog = sys_table('users_notifications_log');
        $cLogRes  = @pg_query($conn, "
            SELECT TO_CHAR(started_at, 'YYYY-MM-DD HH24:MI') AS started_at,
                   status, triggered_by,
                   COALESCE(notifications_created, 0) AS sent
            FROM {$tCronLog}
            ORDER BY started_at DESC
            LIMIT 5
        ");
        $cronRecent  = [];
        $lastCronRun = null;
        if ($cLogRes) {
            while ($row = pg_fetch_assoc($cLogRes)) {
                if ($lastCronRun === null) {
                    $lastCronRun = $row['started_at'];
                }
                $cronRecent[] = $row;
            }
        }

        $tLog  = sys_table('users_log');
        $aRes  = @pg_query($conn, "
            SELECT ul.action, ul.target_table,
                   TO_CHAR(ul.created_at, 'YYYY-MM-DD HH24:MI') AS created_at,
                   u.username
            FROM {$tLog} ul
            LEFT JOIN {$tUsers} u ON u.id = ul.user_id
            ORDER BY ul.created_at DESC
            LIMIT 8
        ");
        $auditRecent = [];
        if ($aRes) {
            while ($row = pg_fetch_assoc($aRes)) {
                $auditRecent[] = $row;
            }
        }

        $dbSizeRes  = @pg_query($conn, 'SELECT pg_database_size(current_database()) AS sz');
        $dbSizeBytes = ($dbSizeRes) ? (int) pg_fetch_result($dbSizeRes, 0, 0) : 0;

        $tMig   = sys_table('migrations');
        $mRes   = @pg_query($conn, "SELECT name FROM {$tMig}");
        $applied = [];
        if ($mRes) {
            while ($row = pg_fetch_row($mRes)) {
                $applied[$row[0]] = true;
            }
        }

        $knownMig = [
            '3.0_baseline',
            '3.1_table_comments',
            '3.1_notes_reminder_time',
            '3.3_user_contact',
            '3.3_clickstats',
        ];
        $pendingMig = count(array_filter($knownMig, static fn($n) => !isset($applied[$n])));

        $versionFile  = __DIR__ . '/../../includes/VERSION';
        $appVersion   = file_exists($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown';
        $pgVerRes     = @pg_query($conn, 'SELECT version()');
        $pgVersionRaw = $pgVerRes ? (string) pg_fetch_result($pgVerRes, 0, 0) : '';
        $pgVersion    = '';
        if (preg_match('/PostgreSQL\s+([\d.]+)/i', $pgVersionRaw, $m)) {
            $pgVersion = $m[1];
        }
        $displayErrors = ini_get('display_errors');
        $memoryLimit   = ini_get('memory_limit');
        $uploadMax     = ini_get('upload_max_filesize');
        $secureCookiesOk = defined('SECURE_COOKIES') ? (bool) SECURE_COOKIES : false;
        $ipHashSaltOk    = defined('IP_HASH_SALT') && IP_HASH_SALT !== '';
        $sessionLifetime = defined('SESSION_MAX_LIFETIME') ? (int) SESSION_MAX_LIFETIME : 0;

        echo json_encode([
            'status'            => 'success',
            'app_version'       => $appVersion,
            'user_total'        => (int) $uRow['total'],
            'user_active'       => (int) $uRow['active'],
            'table_count'       => count($tables),
            'tables'            => $tables,
            'total_records'     => $totalRec,
            'file_count'        => (int) $fRow['n'],
            'file_size_bytes'   => (int) $fRow['total_bytes'],
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
            'pending_migrations' => $pendingMig,
            'memory_limit'       => $memoryLimit,
            'upload_max_filesize' => $uploadMax,
            'secure_cookies_ok'  => $secureCookiesOk,
            'ip_hash_salt_ok'    => $ipHashSaltOk,
            'session_lifetime'   => $sessionLifetime,
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    throw ResponseException::sent();
}
