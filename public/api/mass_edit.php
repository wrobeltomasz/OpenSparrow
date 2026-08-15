<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

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
    } catch (\RuntimeException $e) {
        http_response_code(400);
        exit(json_encode(['error' => 'Unknown table']));
    }

    require_table_access($tableName);

    $cols = $tableCfg['columns'] ?? [];

    if ($colName === 'id') {
        http_response_code(400);
        exit(json_encode(['error' => 'Cannot edit id column']));
    }

    if (!isset($cols[$colName])) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid column']));
    }

    if (($cols[$colName]['type'] ?? '') === 'virtual') {
        http_response_code(400);
        exit(json_encode(['error' => 'Cannot edit virtual columns']));
    }

    $schemaName = $tableCfg['schema'] ?? 'public';
    $tblSql     = pg_ident($schemaName) . '.' . pg_ident($tableName);
    $colSql     = pg_ident($colName);

    return [$tableCfg, $tableName, $cols[$colName], $colSql, $tblSql];
}

function sanitizeRowIds(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $id) {
        $int = filter_var($id, FILTER_VALIDATE_INT);
        if ($int !== false && $int > 0) {
            $ids[] = $int;
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
        http_response_code(400);
        exit(json_encode(['error' => 'No rows selected']));
    }

    [$tableCfg, $tableName, , $colSql, $tblSql] = validateTableColumn($body, $schema);

    $arrParam = pgIntArray($rowIds);

    if (!empty($tableCfg['owner_restricted'])) {
        $uid      = (int)$_SESSION['user_id'];
        $ownerSql = owner_restriction_sql('_t.id', 2, 3);

        $countRes = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$tblSql} AS _t WHERE _t.id = ANY(\$1::int[]){$ownerSql}",
            [$arrParam, $tableName, $uid]
        );
        if (!$countRes) {
            http_response_code(500);
            exit(json_encode(['error' => 'Database query failed.']));
        }
        $count = (int)pg_fetch_result($countRes, 0, 0);
        pg_free_result($countRes);

        $rowRes = @pg_query_params(
            $conn,
            "SELECT _t.id, {$colSql} AS current_val
             FROM {$tblSql} AS _t
             WHERE _t.id = ANY(\$1::int[]){$ownerSql}
             ORDER BY _t.id
             LIMIT 10",
            [$arrParam, $tableName, $uid]
        );
    } else {
        $countRes = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$tblSql} WHERE id = ANY(\$1::int[])",
            [$arrParam]
        );
        if (!$countRes) {
            http_response_code(500);
            exit(json_encode(['error' => 'Database query failed.']));
        }
        $count = (int)pg_fetch_result($countRes, 0, 0);
        pg_free_result($countRes);

        $rowRes = @pg_query_params(
            $conn,
            "SELECT id, {$colSql} AS current_val
             FROM {$tblSql}
             WHERE id = ANY(\$1::int[])
             ORDER BY id
             LIMIT 10",
            [$arrParam]
        );
    }

    if (!$rowRes) {
        http_response_code(500);
        exit(json_encode(['error' => 'Database query failed.']));
    }

    $rows = [];
    while ($row = pg_fetch_assoc($rowRes)) {
        $rows[] = ['id' => (int)$row['id'], 'current' => $row['current_val']];
    }
    pg_free_result($rowRes);

    exit(json_encode(['count' => $count, 'rows' => $rows]));
}

if ($action === 'mass_edit_apply' && $method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $rowIds = sanitizeRowIds($body['row_ids'] ?? []);

    if (empty($rowIds)) {
        http_response_code(400);
        exit(json_encode(['error' => 'No rows selected']));
    }

    $value = array_key_exists('value', $body)
        ? ($body['value'] === null ? null : (string)$body['value'])
        : null;

    [$tableCfg, $tableName, $colCfg, $colSql, $tblSql] = validateTableColumn($body, $schema);

    if (($regexpError = validate_column_regexp($colCfg, $value)) !== null) {
        http_response_code(422);
        exit(json_encode(['error' => $regexpError]));
    }

    $arrParam = pgIntArray($rowIds);

    @pg_query($conn, 'BEGIN');

    if (!empty($tableCfg['owner_restricted'])) {
        $uid      = (int)$_SESSION['user_id'];
        $ownerSql = owner_restriction_sql('_t.id', 3, 4);
        $res = @pg_query_params(
            $conn,
            "UPDATE {$tblSql} AS _t SET {$colSql} = \$2 WHERE _t.id = ANY(\$1::int[]){$ownerSql}",
            [$arrParam, $value, $tableName, $uid]
        );
    } else {
        $res = @pg_query_params(
            $conn,
            "UPDATE {$tblSql} SET {$colSql} = \$2 WHERE id = ANY(\$1::int[])",
            [$arrParam, $value]
        );
    }

    if (!$res) {
        @pg_query($conn, 'ROLLBACK');
        http_response_code(500);
        exit(json_encode(['error' => 'Database update failed.']));
    }

    $affected = pg_affected_rows($res);
    pg_free_result($res);
    @pg_query($conn, 'COMMIT');

    $uid = (int)$_SESSION['user_id'];
    log_user_action($conn, $uid, 'MASS_EDIT', $tableName, null);

    exit(json_encode(['updated' => $affected]));
}

if ($action === 'mass_duplicate' && $method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $rowIds = sanitizeRowIds($body['row_ids'] ?? []);

    if (empty($rowIds)) {
        http_response_code(400);
        exit(json_encode(['error' => 'No rows selected']));
    }

    $tableName = $body['table'] ?? '';

    try {
        $tableCfg = safe_table($schema, $tableName);
    } catch (\RuntimeException $e) {
        http_response_code(400);
        exit(json_encode(['error' => 'Unknown table']));
    }

    require_table_access($tableName);

    $dupCols = [];
    foreach ($tableCfg['columns'] as $colName => $colCfg) {
        if ($colName === 'id') {
            continue;
        }
        if (strtolower($colCfg['type'] ?? '') === 'virtual') {
            continue;
        }
        $dupCols[] = $colName;
    }

    if (empty($dupCols)) {
        http_response_code(422);
        exit(json_encode(['error' => 'No columns to duplicate']));
    }

    $schemaName = $tableCfg['schema'] ?? 'public';
    $tblSql     = pg_ident($schemaName) . '.' . pg_ident($tableName);
    $colIdents  = implode(', ', array_map('pg_ident', $dupCols));
    $arrParam   = pgIntArray($rowIds);

    $uid        = (int)$_SESSION['user_id'];

    @pg_query($conn, 'BEGIN');

    if (!empty($tableCfg['owner_restricted'])) {
        $ownerSql = owner_restriction_sql('_t.id', 2, 3);
        $res = @pg_query_params(
            $conn,
            "INSERT INTO {$tblSql} ({$colIdents})
             SELECT {$colIdents} FROM {$tblSql} AS _t
             WHERE _t.id = ANY(\$1::int[]){$ownerSql}
             RETURNING id",
            [$arrParam, $tableName, $uid]
        );
    } else {
        $res = @pg_query_params(
            $conn,
            "INSERT INTO {$tblSql} ({$colIdents})
             SELECT {$colIdents} FROM {$tblSql}
             WHERE id = ANY(\$1::int[])
             RETURNING id",
            [$arrParam]
        );
    }

    if (!$res) {
        @pg_query($conn, 'ROLLBACK');
        $pgErr    = pg_last_error($conn);
        $isUnique = stripos($pgErr, 'unique') !== false || stripos($pgErr, 'unikaln') !== false;
        http_response_code(422);
        exit(json_encode([
            'error'     => $isUnique ? 'unique_violation' : 'Database duplicate failed.',
            'is_unique' => $isUnique,
        ]));
    }

    $newIds = [];
    while ($row = pg_fetch_row($res)) {
        $newIds[] = (int)$row[0];
    }
    $duplicated = count($newIds);
    pg_free_result($res);

    if (!empty($tableCfg['owner_restricted'])) {
        foreach ($newIds as $newId) {
            set_record_owner($conn, $tableName, $newId, $uid, $uid);
        }
    }

    @pg_query($conn, 'COMMIT');

    log_user_action($conn, $uid, 'MASS_DUPLICATE', $tableName, null);

    exit(json_encode(['duplicated' => $duplicated]));
}

if ($action === 'mass_delete' && $method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $rowIds = sanitizeRowIds($body['row_ids'] ?? []);

    if (empty($rowIds)) {
        http_response_code(400);
        exit(json_encode(['error' => 'No rows selected']));
    }

    $tableName = $body['table'] ?? '';

    try {
        $tableCfg = safe_table($schema, $tableName);
    } catch (\RuntimeException $e) {
        http_response_code(400);
        exit(json_encode(['error' => 'Unknown table']));
    }

    require_table_access($tableName);

    $schemaName = $tableCfg['schema'] ?? 'public';
    $tblSql     = pg_ident($schemaName) . '.' . pg_ident($tableName);
    $arrParam   = pgIntArray($rowIds);

    @pg_query($conn, 'BEGIN');

    if (!empty($tableCfg['owner_restricted'])) {
        $uid      = (int)$_SESSION['user_id'];
        $ownerSql = owner_restriction_sql('_t.id', 2, 3);
        $res = @pg_query_params(
            $conn,
            "DELETE FROM {$tblSql} AS _t WHERE _t.id = ANY(\$1::int[]){$ownerSql}",
            [$arrParam, $tableName, $uid]
        );
    } else {
        $res = @pg_query_params(
            $conn,
            "DELETE FROM {$tblSql} WHERE id = ANY(\$1::int[])",
            [$arrParam]
        );
    }

    if (!$res) {
        @pg_query($conn, 'ROLLBACK');
        http_response_code(500);
        exit(json_encode(['error' => 'Database delete failed.']));
    }

    $affected = pg_affected_rows($res);
    pg_free_result($res);
    @pg_query($conn, 'COMMIT');

    $uid = (int)$_SESSION['user_id'];
    log_user_action($conn, $uid, 'MASS_DELETE', $tableName, null);

    exit(json_encode(['deleted' => $affected]));
}

http_response_code(400);
exit(json_encode(['error' => 'Unknown action']));
