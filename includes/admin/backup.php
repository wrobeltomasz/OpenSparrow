<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

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
        foreach ($tables as $t) {
            $tableName  = $t['name']   ?? '';
            $schemaName = $t['schema'] ?? '';
            if (empty($tableName) || empty($schemaName)) {
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Missing table or schema name.'];
                continue;
            }
            $backupName  = $prefix . '_' . $tableName;
            $safeSchema  = pg_escape_identifier($conn, $schemaName);
            $safeSource  = pg_escape_identifier($conn, $tableName);
            $safeBackup  = pg_escape_identifier($conn, $backupName);
            $sql = "CREATE TABLE $safeSchema.$safeBackup AS SELECT * FROM $safeSchema.$safeSource";
            $res = @pg_query($conn, $sql);
            if ($res) {
                $rows = pg_affected_rows($res);
                $results[] = ['table' => $tableName, 'backup' => $backupName, 'status' => 'success', 'rows' => $rows];
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    throw ResponseException::sent();
}
