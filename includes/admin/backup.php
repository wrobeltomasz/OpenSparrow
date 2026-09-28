<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

require_once __DIR__ . '/backup_common.php';

if ($action === 'backup_settings') {
    admin_try(static function (): void {
        $row = config_get_row('backup');
        admin_ok([
            'settings' => backup_settings(),
            'version'  => $row['version'] ?? null,
        ]);
    });
}

if ($action === 'backup_settings_save') {
    require_not_demo();
    admin_try(static function (): void {
        $data = admin_input();
        $keepCount = $data['keep_count'] ?? 0;
        $retentionDays = $data['retention_days'] ?? 0;
        if (!is_numeric($keepCount) || (int) $keepCount < 0 || (int) $keepCount > BACKUP_MAX_KEEP) {
            throw new AdminApiMessage('Keep count must be a whole number between 0 and ' . BACKUP_MAX_KEEP . '.');
        }
        $maxRetentionDays = BACKUP_MAX_RETENTION_DAYS;
        if (!is_numeric($retentionDays) || (int) $retentionDays < 0 || (int) $retentionDays > $maxRetentionDays) {
            $rangeMessage = 'Retention must be a whole number of days between 0 and ' . $maxRetentionDays . '.';
            throw new AdminApiMessage($rangeMessage);
        }
        admin_config_save_versioned(
            'backup',
            [
                'keep_count'     => (int) $keepCount,
                'retention_days' => (int) $retentionDays,
            ],
            admin_expected_version($data),
            'Failed to save backup settings.'
        );
    });
}

if ($action === 'backup_list') {
    admin_try(static function (): void {
        $conn = admin_conn();

        $settings = backup_settings();
        $rows = [];
        foreach (backup_all_copies($conn) as $row) {
            if (!preg_match('/^(\d{12})_(.+)$/', (string) $row['table_name'], $matches)) {
                continue;
            }
            $timestamp = $matches[1];
            $sourceName = $matches[2];
            $created = DateTimeImmutable::createFromFormat('YmdHi', $timestamp);
            $stale = false;
            if ($settings['keep_count'] >= 1) {
                $existingCopies = backup_find_copies($conn, (string) $row['table_schema'], $sourceName);
                $copyNames = array_map(
                    static fn(array $copyRow): string => (string) $copyRow['backup_name'],
                    $existingCopies
                );
                $position = array_search((string) $row['table_name'], $copyNames, true);
                if ($position !== false && (int) $position >= $settings['keep_count']) {
                    $stale = true;
                }
            }
            if (!$stale && $settings['retention_days'] >= 1 && $created !== false) {
                $cutoff = new DateTimeImmutable('now');
                $cutoff = $cutoff->modify('-' . $settings['retention_days'] . ' days');
                if ($created < $cutoff) {
                    $stale = true;
                }
            }
            $rows[] = [
                'schema'  => $row['table_schema'],
                'name'    => $row['table_name'],
                'source'  => $sourceName,
                'created' => $created !== false ? $created->format('Y-m-d H:i') : $timestamp,
                'stale'   => $stale,
            ];
        }

        admin_ok(['rows' => $rows, 'settings' => $settings]);
    });
}

if ($action === 'backup_cleanup') {
    require_not_demo();
    admin_try(static function (): void {
        $conn = admin_conn();

        $bySource = [];
        foreach (backup_all_copies($conn) as $row) {
            if (!preg_match('/^\d{12}_[0-9a-zA-Z_]+$/', (string) $row['table_name'])) {
                continue;
            }
            $sourceName = preg_replace('/^\d{12}_/', '', (string) $row['table_name']);
            $bySource[(string) $row['table_schema'] . '|' . $sourceName][] = ['backup_name' => $row['table_name']];
        }

        $dropped = 0;
        foreach ($bySource as $key => $existingCopies) {
            [$schemaName, $tableName] = explode('|', $key, 2);
            $stale = backup_stale_copies($tableName, $existingCopies);
            if ($stale !== []) {
                $dropped += backup_drop_tables($conn, $schemaName, $stale);
            }
        }

        admin_ok(['deleted' => $dropped]);
    });
}

if ($action === 'backup_tables') {
    require_not_demo('Disabled in Demo Mode.', 403);
    $input = json_decode(file_get_contents('php://input'), true);
    $tables = $input['tables'] ?? [];
    if (empty($tables) || !is_array($tables)) {
        admin_err('No tables provided.');
    }
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $prefix = date('YmdHi');
        $results = [];
        foreach ($tables as $tableEntry) {
            $tableName  = $tableEntry['name']   ?? '';
            $schemaName = $tableEntry['schema'] ?? '';
            if (empty($tableName) || empty($schemaName)) {
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Missing table or schema name.'];
                continue;
            }
            $backupName  = $prefix . '_' . $tableName;
            $safeSchema  = pg_escape_identifier($conn, $schemaName);
            $safeSource  = pg_escape_identifier($conn, $tableName);
            $safeBackup  = pg_escape_identifier($conn, $backupName);
            $sql = "CREATE TABLE $safeSchema.$safeBackup AS SELECT * FROM $safeSchema.$safeSource";
            $result = @pg_query($conn, $sql);
            if ($result) {
                $rows = pg_affected_rows($result);
                $results[] = ['table' => $tableName, 'backup' => $backupName, 'status' => 'success', 'rows' => $rows];
                $results[count($results) - 1]['dropped'] = backup_apply_retention($conn, $schemaName, $tableName);
            } else {
                error_log('[admin_api][backup_tables] ' . pg_last_error($conn));
                $results[] = [
                    'table'   => $tableName,
                    'status'  => 'error',
                    'message' => 'Database error. Check server logs.',
                ];
            }
        }
        echo json_encode(['status' => 'success', 'results' => $results]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}
