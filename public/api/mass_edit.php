<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\HttpException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

require_once __DIR__ . '/../../includes/bootstrap.php';

$conn = os_api_bootstrap(['role' => 'editor']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

require_once __DIR__ . '/../../includes/config_store.php';
$schema = config_get('schema') ?? ['tables' => []];

function validateTableColumn(array $body, array $schema): array
{
    $tableName = $body['table']  ?? '';
    $colName   = $body['column'] ?? '';

    try {
        $tableCfg = safe_table($schema, $tableName);
    } catch (\RuntimeException $exception) {
        throw new BadRequestException('Unknown table');
    }

    require_table_access($tableName);

    $columns = $tableCfg['columns'] ?? [];

    if ($colName === 'id') {
        throw new BadRequestException('Cannot edit id column');
    }

    if (!isset($columns[$colName])) {
        throw new BadRequestException('Invalid column');
    }

    if (($columns[$colName]['type'] ?? '') === 'virtual') {
        throw new BadRequestException('Cannot edit virtual columns');
    }

    $schemaName = $tableCfg['schema'] ?? 'public';
    $qualifiedTable     = pg_ident($schemaName) . '.' . pg_ident($tableName);
    $colSql     = pg_ident($colName);

    return [$tableCfg, $tableName, $columns[$colName], $colSql, $qualifiedTable];
}

function sanitizeRowIds(mixed $rawIds): array
{
    if (!is_array($rawIds)) {
        return [];
    }

    $ids = [];
    foreach ($rawIds as $id) {
        $validatedId = filter_var($id, FILTER_VALIDATE_INT);
        if ($validatedId !== false && $validatedId > 0) {
            $ids[] = $validatedId;
        }
    }

    return array_values(array_unique($ids));
}

function pgIntArray(array $ids): string
{
    return '{' . implode(',', array_map('intval', $ids)) . '}';
}

if ($action === 'mass_edit_preview' && $method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $rowIds = sanitizeRowIds($body['row_ids'] ?? []);

    if (empty($rowIds)) {
        throw new BadRequestException('No rows selected');
    }

    [$tableCfg, $tableName, , $colSql, $qualifiedTable] = validateTableColumn($body, $schema);

    $rowIdsArray = pgIntArray($rowIds);

    if (!empty($tableCfg['owner_restricted'])) {
        $userId      = (int)$_SESSION['user_id'];
        $ownerSql = owner_restriction_sql('_t.id', 2, 3);

        $countResult = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$qualifiedTable} AS _t WHERE _t.id = ANY(\$1::int[]){$ownerSql}",
            [$rowIdsArray, $tableName, $userId]
        );
        if (!$countResult) {
            throw new ServerErrorException('Database query failed.');
        }
        $count = (int)pg_fetch_result($countResult, 0, 0);
        pg_free_result($countResult);

        $rowResult = @pg_query_params(
            $conn,
            "SELECT _t.id, {$colSql} AS current_val
             FROM {$qualifiedTable} AS _t
             WHERE _t.id = ANY(\$1::int[]){$ownerSql}
             ORDER BY _t.id
             LIMIT 10",
            [$rowIdsArray, $tableName, $userId]
        );
    } else {
        $countResult = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$qualifiedTable} WHERE id = ANY(\$1::int[])",
            [$rowIdsArray]
        );
        if (!$countResult) {
            throw new ServerErrorException('Database query failed.');
        }
        $count = (int)pg_fetch_result($countResult, 0, 0);
        pg_free_result($countResult);

        $rowResult = @pg_query_params(
            $conn,
            "SELECT id, {$colSql} AS current_val
             FROM {$qualifiedTable}
             WHERE id = ANY(\$1::int[])
             ORDER BY id
             LIMIT 10",
            [$rowIdsArray]
        );
    }

    if (!$rowResult) {
        throw new ServerErrorException('Database query failed.');
    }

    $rows = [];
    while ($row = pg_fetch_assoc($rowResult)) {
        $rows[] = ['id' => (int)$row['id'], 'current' => $row['current_val']];
    }
    pg_free_result($rowResult);

    throw ResponseException::encoded(['count' => $count, 'rows' => $rows]);
}

if ($action === 'mass_edit_apply' && $method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $rowIds = sanitizeRowIds($body['row_ids'] ?? []);

    if (empty($rowIds)) {
        throw new BadRequestException('No rows selected');
    }

    $value = array_key_exists('value', $body)
        ? ($body['value'] === null ? null : (string)$body['value'])
        : null;

    [$tableCfg, $tableName, $colCfg, $colSql, $qualifiedTable] = validateTableColumn($body, $schema);

    if (($regexpError = validate_column_regexp($colCfg, $value)) !== null) {
        throw HttpException::fromStatus(422, (string) $regexpError);
    }

    $rowIdsArray = pgIntArray($rowIds);

    @pg_query($conn, 'BEGIN');

    if (!empty($tableCfg['owner_restricted'])) {
        $userId      = (int)$_SESSION['user_id'];
        $ownerSql = owner_restriction_sql('_t.id', 3, 4);
        $result = @pg_query_params(
            $conn,
            "UPDATE {$qualifiedTable} AS _t SET {$colSql} = \$2 WHERE _t.id = ANY(\$1::int[]){$ownerSql}",
            [$rowIdsArray, $value, $tableName, $userId]
        );
    } else {
        $result = @pg_query_params(
            $conn,
            "UPDATE {$qualifiedTable} SET {$colSql} = \$2 WHERE id = ANY(\$1::int[])",
            [$rowIdsArray, $value]
        );
    }

    if (!$result) {
        @pg_query($conn, 'ROLLBACK');
        throw new ServerErrorException('Database update failed.');
    }

    $affected = pg_affected_rows($result);
    pg_free_result($result);
    @pg_query($conn, 'COMMIT');

    $userId = (int)$_SESSION['user_id'];
    log_user_action($conn, $userId, 'MASS_EDIT', $tableName, null);

    throw ResponseException::encoded(['updated' => $affected]);
}

if ($action === 'mass_duplicate' && $method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $rowIds = sanitizeRowIds($body['row_ids'] ?? []);

    if (empty($rowIds)) {
        throw new BadRequestException('No rows selected');
    }

    $tableName = $body['table'] ?? '';

    try {
        $tableCfg = safe_table($schema, $tableName);
    } catch (\RuntimeException $exception) {
        throw new BadRequestException('Unknown table');
    }

    require_table_access($tableName);

    $duplicateColumns = [];
    foreach ($tableCfg['columns'] as $colName => $colCfg) {
        if ($colName === 'id') {
            continue;
        }
        if (strtolower($colCfg['type'] ?? '') === 'virtual') {
            continue;
        }
        $duplicateColumns[] = $colName;
    }

    if (empty($duplicateColumns)) {
        throw HttpException::fromStatus(422, 'No columns to duplicate');
    }

    $schemaName = $tableCfg['schema'] ?? 'public';
    $qualifiedTable     = pg_ident($schemaName) . '.' . pg_ident($tableName);
    $colIdents  = implode(', ', array_map('pg_ident', $duplicateColumns));
    $rowIdsArray   = pgIntArray($rowIds);

    $userId        = (int)$_SESSION['user_id'];

    @pg_query($conn, 'BEGIN');

    if (!empty($tableCfg['owner_restricted'])) {
        $ownerSql = owner_restriction_sql('_t.id', 2, 3);
        $result = @pg_query_params(
            $conn,
            "INSERT INTO {$qualifiedTable} ({$colIdents})
             SELECT {$colIdents} FROM {$qualifiedTable} AS _t
             WHERE _t.id = ANY(\$1::int[]){$ownerSql}
             RETURNING id",
            [$rowIdsArray, $tableName, $userId]
        );
    } else {
        $result = @pg_query_params(
            $conn,
            "INSERT INTO {$qualifiedTable} ({$colIdents})
             SELECT {$colIdents} FROM {$qualifiedTable}
             WHERE id = ANY(\$1::int[])
             RETURNING id",
            [$rowIdsArray]
        );
    }

    if (!$result) {
        @pg_query($conn, 'ROLLBACK');
        $pgError    = pg_last_error($conn);
        $isUnique = stripos($pgError, 'unique') !== false || stripos($pgError, 'unikaln') !== false;
        throw HttpException::fromStatus(
            422,
            $isUnique ? 'unique_violation' : 'Database duplicate failed.',
            [
                'error'     => $isUnique ? 'unique_violation' : 'Database duplicate failed.',
                'is_unique' => $isUnique,
            ]
        );
    }

    $newIds = [];
    while ($row = pg_fetch_row($result)) {
        $newIds[] = (int)$row[0];
    }
    $duplicated = count($newIds);
    pg_free_result($result);

    if (!empty($tableCfg['owner_restricted'])) {
        foreach ($newIds as $newId) {
            set_record_owner($conn, $tableName, $newId, $userId, $userId);
        }
    }

    @pg_query($conn, 'COMMIT');

    log_user_action($conn, $userId, 'MASS_DUPLICATE', $tableName, null);

    throw ResponseException::encoded(['duplicated' => $duplicated]);
}

if ($action === 'mass_delete' && $method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $rowIds = sanitizeRowIds($body['row_ids'] ?? []);

    if (empty($rowIds)) {
        throw new BadRequestException('No rows selected');
    }

    $tableName = $body['table'] ?? '';

    try {
        $tableCfg = safe_table($schema, $tableName);
    } catch (\RuntimeException $exception) {
        throw new BadRequestException('Unknown table');
    }

    require_table_access($tableName);

    $schemaName = $tableCfg['schema'] ?? 'public';
    $qualifiedTable     = pg_ident($schemaName) . '.' . pg_ident($tableName);
    $rowIdsArray   = pgIntArray($rowIds);

    @pg_query($conn, 'BEGIN');

    if (!empty($tableCfg['owner_restricted'])) {
        $userId      = (int)$_SESSION['user_id'];
        $ownerSql = owner_restriction_sql('_t.id', 2, 3);
        $result = @pg_query_params(
            $conn,
            "DELETE FROM {$qualifiedTable} AS _t WHERE _t.id = ANY(\$1::int[]){$ownerSql}",
            [$rowIdsArray, $tableName, $userId]
        );
    } else {
        $result = @pg_query_params(
            $conn,
            "DELETE FROM {$qualifiedTable} WHERE id = ANY(\$1::int[])",
            [$rowIdsArray]
        );
    }

    if (!$result) {
        @pg_query($conn, 'ROLLBACK');
        throw new ServerErrorException('Database delete failed.');
    }

    $affected = pg_affected_rows($result);
    pg_free_result($result);
    @pg_query($conn, 'COMMIT');

    $userId = (int)$_SESSION['user_id'];
    log_user_action($conn, $userId, 'MASS_DELETE', $tableName, null);

    throw ResponseException::encoded(['deleted' => $affected]);
}

throw new BadRequestException('Unknown action');
