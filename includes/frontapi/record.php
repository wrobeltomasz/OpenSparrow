<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function frontapi_record_patch(FrontApiWriteContext $ctx): never
{
    $conn       = $ctx->conn;
    $body       = $ctx->body;
    $table      = $ctx->table;
    $tableCfg   = $ctx->tableCfg;
    $schemaName = $ctx->schemaName;
    $idCol      = $ctx->idCol;
    $userId     = $ctx->userId;

    $recordId = (int)($body['id']);
    if ($recordId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid record ID']);
        exit;
    }
    $col = $body['column'];
    if (!isset($tableCfg['columns'][$col])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid column specified']);
        exit;
    }

    if ($col === $idCol) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot edit PK']);
        exit;
    }

    check_record_ownership($conn, $tableCfg, $table, $recordId, $userId, 'Forbidden: you do not own this record.');

    $colType = strtolower($tableCfg['columns'][$col]['type'] ?? '');
    $cast = '';
    $val = $body['value'];
    if (str_contains($colType, 'bool')) {
        $val = normalize_boolean($val);
        $cast = '::boolean';
    } elseif ($val === '') {
        $val = null;
    }

    $regexpError = validate_column_regexp($tableCfg['columns'][$col], $val);
    if (!str_contains($colType, 'bool') && $regexpError !== null) {
        http_response_code(422);
        echo json_encode(['error' => $regexpError]);
        exit;
    }

    $oldRecord = auto_capture_old_record($conn, $schemaName, $table, $recordId);

    $sql = sprintf(
        'UPDATE %s.%s SET %s = $1%s WHERE %s = $2',
        pg_ident($schemaName),
        pg_ident($table),
        pg_ident($col),
        $cast,
        pg_ident($idCol)
    );
    $res = @pg_query_params($conn, $sql, [$val, $recordId]);
    if (!$res) {
        error_log('[api][patch] ' . pg_last_error($conn));
        http_response_code(422);
        echo json_encode(['error' => 'Database error']);
        exit;
    }

    $logId = log_user_action($conn, $userId, 'UPDATE', $table, $recordId);
    if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
        snapshot_record($conn, $schemaName, $table, $recordId, $logId);
    }
    evaluate_automation_rules($conn, $schemaName, $table, $recordId, 'update', $userId, $oldRecord);
    echo json_encode(['ok' => true]);
    exit;
}

function frontapi_record_insert(FrontApiWriteContext $ctx): never
{
    $conn       = $ctx->conn;
    $body       = $ctx->body;
    $table      = $ctx->table;
    $tableCfg   = $ctx->tableCfg;
    $schemaName = $ctx->schemaName;
    $idCol      = $ctx->idCol;

    $cols = [];
    $vals = [];
    $ph   = [];
    $i    = 1;
    foreach ($tableCfg['columns'] as $colName => $colCfg) {
        if ($colName === $idCol) {
            continue;
        }

        $type = strtolower($colCfg['type'] ?? '');
        $val = $body['data'][$colName] ?? null;
        if (str_contains($type, 'bool')) {
            $val = normalize_boolean($val);
        } elseif ($val === '') {
            $val = null;
        }

        $isNotNull = !empty($colCfg['not_null']);
        if ($val === null && $isNotNull) {
            $val = type_min_value($type);
        }

        if (!str_contains($type, 'bool') && ($regexpError = validate_column_regexp($colCfg, $val)) !== null) {
            http_response_code(422);
            echo json_encode(['error' => $regexpError, 'column' => $colName]);
            exit;
        }

        if ($val !== null) {
            $cols[] = $colName;
            $vals[] = $val;
            $ph[]   = str_contains($type, 'bool') ? '$' . $i . '::boolean' : '$' . $i;
            $i++;
        }
    }

    if (empty($cols)) {
        $sql = sprintf(
            'INSERT INTO %s.%s DEFAULT VALUES RETURNING %s',
            pg_ident($schemaName),
            pg_ident($table),
            pg_ident($idCol)
        );
        $res = @pg_query($conn, $sql);
    } else {
        $sql = sprintf(
            'INSERT INTO %s.%s (%s) VALUES (%s) RETURNING %s',
            pg_ident($schemaName),
            pg_ident($table),
            implode(', ', array_map('pg_ident', $cols)),
            implode(', ', $ph),
            pg_ident($idCol)
        );
        $res = @pg_query_params($conn, $sql, $vals);
    }

    if (!$res) {
        error_log('[api][insert] ' . pg_last_error($conn));
        http_response_code(422);
        echo json_encode(['error' => 'Database error']);
        exit;
    }

    $row = pg_fetch_assoc($res);
    pg_free_result($res);
    $newId = $row[$idCol] ?? null;
    if ($newId !== null) {
        $userId = $ctx->userId;
        $logId  = log_user_action($conn, $userId, 'INSERT', $table, (int)$newId);
        if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
            snapshot_record($conn, $schemaName, $table, (int) $newId, $logId);
        }
        set_record_owner($conn, $table, (int)$newId, $userId, $userId);
        evaluate_automation_rules($conn, $schemaName, $table, (int)$newId, 'create', $userId);
    }

    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

function frontapi_record_duplicate(FrontApiWriteContext $ctx): never
{
    $conn       = $ctx->conn;
    $body       = $ctx->body;
    $table      = $ctx->table;
    $tableCfg   = $ctx->tableCfg;
    $schemaName = $ctx->schemaName;
    $idCol      = $ctx->idCol;

    $srcId = (int)$body['id'];
    if ($srcId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ID']);
        exit;
    }

    check_record_ownership($conn, $tableCfg, $table, $srcId, $ctx->userId, 'Forbidden: you do not own this record.');

    $dupCols = [];
    foreach ($tableCfg['columns'] as $colName => $colCfg) {
        if ($colName === $idCol) {
            continue;
        }
        if (strtolower($colCfg['type'] ?? '') === 'virtual') {
            continue;
        }
        $dupCols[] = $colName;
    }

    if (empty($dupCols)) {
        http_response_code(422);
        echo json_encode(['error' => 'No columns to duplicate']);
        exit;
    }

    $colIdents = implode(', ', array_map('pg_ident', $dupCols));
    $sql = sprintf(
        'INSERT INTO %s.%s (%s) SELECT %s FROM %s.%s WHERE %s = $1 RETURNING %s',
        pg_ident($schemaName),
        pg_ident($table),
        $colIdents,
        $colIdents,
        pg_ident($schemaName),
        pg_ident($table),
        pg_ident($idCol),
        pg_ident($idCol)
    );
    $res = @pg_query_params($conn, $sql, [$srcId]);
    if (!$res) {
        $pgErr = pg_last_error($conn);
        error_log('[api][duplicate] ' . $pgErr);
        http_response_code(422);
        if (stripos($pgErr, 'unique') !== false || stripos($pgErr, 'unikaln') !== false) {
            $col = '';
            if (preg_match('/[Kk]ey\s*\(([^)]+)\)|Klucz\s*\(([^)]+)\)/', $pgErr, $m)) {
                    $col = $m[1] ?: $m[2];
            }
            $msg = $col
                ? t('grid.duplicate_unique', ['col' => $col])
                : t('grid.duplicate_conflict');
            echo json_encode(['error' => $msg]);
        } else {
            echo json_encode(['error' => 'Database error']);
        }
        exit;
    }

    $row = pg_fetch_assoc($res);
    pg_free_result($res);
    $newId = $row[$idCol] ?? null;
    if ($newId !== null) {
        $userId = $ctx->userId;
        $logId  = log_user_action($conn, $userId, 'INSERT', $table, (int)$newId);
        if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
            snapshot_record($conn, $schemaName, $table, (int)$newId, $logId);
        }
        set_record_owner($conn, $table, (int)$newId, $userId, $userId);
        evaluate_automation_rules($conn, $schemaName, $table, (int)$newId, 'create', $userId);
    }

    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

function frontapi_record_delete(FrontApiWriteContext $ctx): never
{
    $conn       = $ctx->conn;
    $body       = $ctx->body;
    $table      = $ctx->table;
    $tableCfg   = $ctx->tableCfg;
    $schemaName = $ctx->schemaName;
    $idCol      = $ctx->idCol;
    $userId     = $ctx->userId;

    $deleteId = (int)$body['id'];
    if ($deleteId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid record ID']);
        exit;
    }

    check_record_ownership($conn, $tableCfg, $table, $deleteId, $userId, 'Forbidden: you do not own this record.');

    $deletedRecord = auto_capture_old_record($conn, $schemaName, $table, $deleteId, 'delete');

    $sql = sprintf('DELETE FROM %s.%s WHERE %s=$1', pg_ident($schemaName), pg_ident($table), pg_ident($idCol));
    $res = @pg_query_params($conn, $sql, [$deleteId]);
    if (!$res) {
        error_log('[api][delete] ' . pg_last_error($conn));
        http_response_code(422);
        echo json_encode(['error' => 'Database error']);
        exit;
    }

    log_user_action($conn, $userId, 'DELETE', $table, $deleteId);
    evaluate_automation_rules($conn, $schemaName, $table, $deleteId, 'delete', $userId, $deletedRecord);
    echo json_encode(['ok' => true]);
    exit;
}
