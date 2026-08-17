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
    $tableConfig   = $context->tableCfg;
    $schemaName = $context->schemaName;
    $idColumn   = $context->idColumn;
    $userId     = $context->userId;

    $recordId = (int)($body['id']);
    if ($recordId <= 0) {
        throw new BadRequestException('Invalid record ID');
    }
    $column = $body['column'];
    if (!isset($tableConfig['columns'][$column])) {
        throw new BadRequestException('Invalid column specified');
    }

    if ($column === $idColumn) {
        throw new BadRequestException('Cannot edit PK');
    }

    check_record_ownership($conn, $tableConfig, $table, $recordId, $userId, 'Forbidden: you do not own this record.');

    $columnType = strtolower($tableConfig['columns'][$column]['type'] ?? '');
    $cast = '';
    $value = $body['value'];
    if (str_contains($columnType, 'bool')) {
        $value = normalize_boolean($value);
        $cast = '::boolean';
    } elseif ($value === '') {
        $value = null;
    }

    $regexpError = validate_column_regexp($tableConfig['columns'][$column], $value);
    if (!str_contains($columnType, 'bool') && $regexpError !== null) {
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
        pg_ident($idColumn)
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
    $tableConfig   = $context->tableCfg;
    $schemaName = $context->schemaName;
    $idColumn   = $context->idColumn;

    $columns = [];
    $values = [];
    $placeholders   = [];
    $placeholderIndex    = 1;
    foreach ($tableConfig['columns'] as $columnName => $columnConfig) {
        if ($columnName === $idColumn) {
            continue;
        }

        $type = strtolower($columnConfig['type'] ?? '');
        $value = $body['data'][$columnName] ?? null;
        if (str_contains($type, 'bool')) {
            $value = normalize_boolean($value);
        } elseif ($value === '') {
            $value = null;
        }

        $isNotNull = !empty($columnConfig['not_null']);
        if ($value === null && $isNotNull) {
            $value = type_min_value($type);
        }

        if (!str_contains($type, 'bool') && ($regexpError = validate_column_regexp($columnConfig, $value)) !== null) {
            http_response_code(422);
            throw ResponseException::encoded(['error' => $regexpError, 'column' => $columnName]);
        }

        if ($value !== null) {
            $columns[] = $columnName;
            $values[] = $value;
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
            pg_ident($idColumn)
        );
        $result = @pg_query($conn, $sql);
    } else {
        $sql = sprintf(
            'INSERT INTO %s.%s (%s) VALUES (%s) RETURNING %s',
            pg_ident($schemaName),
            pg_ident($table),
            implode(', ', array_map('pg_ident', $columns)),
            implode(', ', $placeholders),
            pg_ident($idColumn)
        );
        $result = @pg_query_params($conn, $sql, $values);
    }

    if (!$result) {
        error_log('[api][insert] ' . pg_last_error($conn));
        http_response_code(422);
        throw ResponseException::encoded(['error' => 'Database error']);
    }

    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    $newId = $row[$idColumn] ?? null;
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
    $tableConfig   = $context->tableCfg;
    $schemaName = $context->schemaName;
    $idColumn   = $context->idColumn;

    $sourceId = (int)$body['id'];
    if ($sourceId <= 0) {
        throw new BadRequestException('Invalid ID');
    }

    check_record_ownership(
        $conn,
        $tableConfig,
        $table,
        $sourceId,
        $context->userId,
        'Forbidden: you do not own this record.'
    );

    $duplicateColumns = [];
    foreach ($tableConfig['columns'] as $columnName => $columnConfig) {
        if ($columnName === $idColumn) {
            continue;
        }
        if (strtolower($columnConfig['type'] ?? '') === 'virtual') {
            continue;
        }
        $duplicateColumns[] = $columnName;
    }

    if (empty($duplicateColumns)) {
        http_response_code(422);
        throw ResponseException::encoded(['error' => 'No columns to duplicate']);
    }

    $columnIdentifiers = implode(', ', array_map('pg_ident', $duplicateColumns));
    $sql = sprintf(
        'INSERT INTO %s.%s (%s) SELECT %s FROM %s.%s WHERE %s = $1 RETURNING %s',
        pg_ident($schemaName),
        pg_ident($table),
        $columnIdentifiers,
        $columnIdentifiers,
        pg_ident($schemaName),
        pg_ident($table),
        pg_ident($idColumn),
        pg_ident($idColumn)
    );
    $result = @pg_query_params($conn, $sql, [$sourceId]);
    if (!$result) {
        $pgError = pg_last_error($conn);
        error_log('[api][duplicate] ' . $pgError);
        http_response_code(422);
        if (stripos($pgError, 'unique') !== false || stripos($pgError, 'unikaln') !== false) {
            $column = '';
            if (preg_match('/[Kk]ey\s*\(([^)]+)\)|Klucz\s*\(([^)]+)\)/', $pgError, $matches)) {
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
    $newId = $row[$idColumn] ?? null;
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
    $tableConfig   = $context->tableCfg;
    $schemaName = $context->schemaName;
    $idColumn   = $context->idColumn;
    $userId     = $context->userId;

    $deleteId = (int)$body['id'];
    if ($deleteId <= 0) {
        throw new BadRequestException('Invalid record ID');
    }

    check_record_ownership($conn, $tableConfig, $table, $deleteId, $userId, 'Forbidden: you do not own this record.');

    $deletedRecord = auto_capture_old_record($conn, $schemaName, $table, $deleteId, 'delete');

    $sql = sprintf('DELETE FROM %s.%s WHERE %s=$1', pg_ident($schemaName), pg_ident($table), pg_ident($idColumn));
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
