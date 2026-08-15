<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\ResponseException;

function frontapi_record_patch(FrontApiWriteContext $context): never
{
    $conn       = $context->conn;
    $body       = $context->body;
    $table      = $context->table;
    $tableCfg   = $context->tableCfg;
    $schemaName = $context->schemaName;
    $idCol      = $context->idCol;
    $userId     = $context->userId;

    $recordId = (int)($body['id']);
    if ($recordId <= 0) {
        throw new BadRequestException('Invalid record ID');
    }
    $column = $body['column'];
    if (!isset($tableCfg['columns'][$column])) {
        throw new BadRequestException('Invalid column specified');
    }

    if ($column === $idCol) {
        throw new BadRequestException('Cannot edit PK');
    }

    check_record_ownership($conn, $tableCfg, $table, $recordId, $userId, 'Forbidden: you do not own this record.');

    $colType = strtolower($tableCfg['columns'][$column]['type'] ?? '');
    $cast = '';
    $value = $body['value'];
    if (str_contains($colType, 'bool')) {
        $value = normalize_boolean($value);
        $cast = '::boolean';
    } elseif ($value === '') {
        $value = null;
    }

    $regexpError = validate_column_regexp($tableCfg['columns'][$column], $value);
    if (!str_contains($colType, 'bool') && $regexpError !== null) {
        http_response_code(422);
        throw ResponseException::encoded(['error' => $regexpError]);
    }

    $oldRecord = auto_capture_old_record($conn, $schemaName, $table, $recordId);

    $sql = sprintf(
        'UPDATE %s.%s SET %s = $1%s WHERE %s = $2',
        pg_ident($schemaName),
        pg_ident($table),
        pg_ident($column),
        $cast,
        pg_ident($idCol)
    );
    $result = @pg_query_params($conn, $sql, [$value, $recordId]);
    if (!$result) {
        error_log('[api][patch] ' . pg_last_error($conn));
        http_response_code(422);
        throw ResponseException::encoded(['error' => 'Database error']);
    }

    $logId = log_user_action($conn, $userId, 'UPDATE', $table, $recordId);
    if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
        snapshot_record($conn, $schemaName, $table, $recordId, $logId);
    }
    evaluate_automation_rules($conn, $schemaName, $table, $recordId, 'update', $userId, $oldRecord);
    throw ResponseException::encoded(['ok' => true]);
}

function frontapi_record_insert(FrontApiWriteContext $context): never
{
    $conn       = $context->conn;
    $body       = $context->body;
    $table      = $context->table;
    $tableCfg   = $context->tableCfg;
    $schemaName = $context->schemaName;
    $idCol      = $context->idCol;

    $columns = [];
    $vals = [];
    $placeholders   = [];
    $placeholderIndex    = 1;
    foreach ($tableCfg['columns'] as $colName => $colCfg) {
        if ($colName === $idCol) {
            continue;
        }

        $type = strtolower($colCfg['type'] ?? '');
        $value = $body['data'][$colName] ?? null;
        if (str_contains($type, 'bool')) {
            $value = normalize_boolean($value);
        } elseif ($value === '') {
            $value = null;
        }

        $isNotNull = !empty($colCfg['not_null']);
        if ($value === null && $isNotNull) {
            $value = type_min_value($type);
        }

        if (!str_contains($type, 'bool') && ($regexpError = validate_column_regexp($colCfg, $value)) !== null) {
            http_response_code(422);
            throw ResponseException::encoded(['error' => $regexpError, 'column' => $colName]);
        }

        if ($value !== null) {
            $columns[] = $colName;
            $vals[] = $value;
            $placeholders[]   = str_contains($type, 'bool')
                ? '$' . $placeholderIndex . '::boolean'
                : '$' . $placeholderIndex;
            $placeholderIndex++;
        }
    }

    if (empty($columns)) {
        $sql = sprintf(
            'INSERT INTO %s.%s DEFAULT VALUES RETURNING %s',
            pg_ident($schemaName),
            pg_ident($table),
            pg_ident($idCol)
        );
        $result = @pg_query($conn, $sql);
    } else {
        $sql = sprintf(
            'INSERT INTO %s.%s (%s) VALUES (%s) RETURNING %s',
            pg_ident($schemaName),
            pg_ident($table),
            implode(', ', array_map('pg_ident', $columns)),
            implode(', ', $placeholders),
            pg_ident($idCol)
        );
        $result = @pg_query_params($conn, $sql, $vals);
    }

    if (!$result) {
        error_log('[api][insert] ' . pg_last_error($conn));
        http_response_code(422);
        throw ResponseException::encoded(['error' => 'Database error']);
    }

    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    $newId = $row[$idCol] ?? null;
    if ($newId !== null) {
        $userId = $context->userId;
        $logId  = log_user_action($conn, $userId, 'INSERT', $table, (int)$newId);
        if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
            snapshot_record($conn, $schemaName, $table, (int) $newId, $logId);
        }
        set_record_owner($conn, $table, (int)$newId, $userId, $userId);
        evaluate_automation_rules($conn, $schemaName, $table, (int)$newId, 'create', $userId);
    }

    throw ResponseException::encoded(['ok' => true, 'id' => $newId]);
}

function frontapi_record_duplicate(FrontApiWriteContext $context): never
{
    $conn       = $context->conn;
    $body       = $context->body;
    $table      = $context->table;
    $tableCfg   = $context->tableCfg;
    $schemaName = $context->schemaName;
    $idCol      = $context->idCol;

    $srcId = (int)$body['id'];
    if ($srcId <= 0) {
        throw new BadRequestException('Invalid ID');
    }

    check_record_ownership(
        $conn,
        $tableCfg,
        $table,
        $srcId,
        $context->userId,
        'Forbidden: you do not own this record.'
    );

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
        throw ResponseException::encoded(['error' => 'No columns to duplicate']);
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
    $result = @pg_query_params($conn, $sql, [$srcId]);
    if (!$result) {
        $pgErr = pg_last_error($conn);
        error_log('[api][duplicate] ' . $pgErr);
        http_response_code(422);
        if (stripos($pgErr, 'unique') !== false || stripos($pgErr, 'unikaln') !== false) {
            $column = '';
            if (preg_match('/[Kk]ey\s*\(([^)]+)\)|Klucz\s*\(([^)]+)\)/', $pgErr, $matches)) {
                    $column = $matches[1] ?: $matches[2];
            }
            $message = $column
                ? t('grid.duplicate_unique', ['col' => $column])
                : t('grid.duplicate_conflict');
            echo json_encode(['error' => $message]);
        } else {
            echo json_encode(['error' => 'Database error']);
        }
        throw ResponseException::sent();
    }

    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    $newId = $row[$idCol] ?? null;
    if ($newId !== null) {
        $userId = $context->userId;
        $logId  = log_user_action($conn, $userId, 'INSERT', $table, (int)$newId);
        if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
            snapshot_record($conn, $schemaName, $table, (int)$newId, $logId);
        }
        set_record_owner($conn, $table, (int)$newId, $userId, $userId);
        evaluate_automation_rules($conn, $schemaName, $table, (int)$newId, 'create', $userId);
    }

    throw ResponseException::encoded(['ok' => true, 'id' => $newId]);
}

function frontapi_record_delete(FrontApiWriteContext $context): never
{
    $conn       = $context->conn;
    $body       = $context->body;
    $table      = $context->table;
    $tableCfg   = $context->tableCfg;
    $schemaName = $context->schemaName;
    $idCol      = $context->idCol;
    $userId     = $context->userId;

    $deleteId = (int)$body['id'];
    if ($deleteId <= 0) {
        throw new BadRequestException('Invalid record ID');
    }

    check_record_ownership($conn, $tableCfg, $table, $deleteId, $userId, 'Forbidden: you do not own this record.');

    $deletedRecord = auto_capture_old_record($conn, $schemaName, $table, $deleteId, 'delete');

    $sql = sprintf('DELETE FROM %s.%s WHERE %s=$1', pg_ident($schemaName), pg_ident($table), pg_ident($idCol));
    $result = @pg_query_params($conn, $sql, [$deleteId]);
    if (!$result) {
        error_log('[api][delete] ' . pg_last_error($conn));
        http_response_code(422);
        throw ResponseException::encoded(['error' => 'Database error']);
    }

    log_user_action($conn, $userId, 'DELETE', $table, $deleteId);
    evaluate_automation_rules($conn, $schemaName, $table, $deleteId, 'delete', $userId, $deletedRecord);
    throw ResponseException::encoded(['ok' => true]);
}
