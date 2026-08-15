<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$conn = os_api_bootstrap(['csrf' => 'manual']);

['action' => $action, 'body' => $body] = os_api_action();

os_api_dispatch($action, [
    'get'      => fn() => owners_action_get($conn),
    'history'  => fn() => owners_action_history($conn),
    'editors'  => fn() => owners_action_editors($conn),
    'mine'     => fn() => owners_action_mine($conn),
    'set'      => fn() => owners_action_set($conn, $body),
    'mass_set' => fn() => owners_action_mass_set($conn, $body),
], 'api_owners');

function owners_action_get($conn): void
{
    requireLogin();

    $table    = validatedTable(trim($_GET['table'] ?? ''));
    $recordId = (int)($_GET['id'] ?? 0);

    if ($recordId <= 0) {
        jsonError('id must be a positive integer.', 400);
    }

    $sql = "
        SELECT o.owner_id, u.username, u.avatar_id, o.changed_at
        FROM " . sys_table('record_owners') . " o
        LEFT JOIN " . sys_table('users') . " u ON u.id = o.owner_id
        WHERE o.table_name = \$1 AND o.record_id = \$2 AND o.is_current = true
    ";

    $result = pg_query_params($conn, $sql, [$table, $recordId]);
    if (!$result) {
        error_log('[api_owners owners_action_get] ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $row = pg_fetch_assoc($result);
    if (!$row) {
        jsonSuccess(['owner' => null]);
    }

    $owner = [
        'id'         => $row['owner_id'] !== null ? (int)$row['owner_id'] : null,
        'username'   => $row['username'],
        'avatar_id'  => $row['avatar_id'] !== null ? (int)$row['avatar_id'] : null,
        'changed_at' => $row['changed_at'],
    ];

    jsonSuccess(['owner' => $owner]);
}

function owners_action_history($conn): void
{
    requireLogin();

    $table    = validatedTable(trim($_GET['table'] ?? ''));
    $recordId = (int)($_GET['id'] ?? 0);

    if ($recordId <= 0) {
        jsonError('id must be a positive integer.', 400);
    }

    $sql = "
        SELECT o.owner_id, u.username, o.changed_at, cb.username AS changed_by_name
        FROM " . sys_table('record_owners') . " o
        LEFT JOIN " . sys_table('users') . " u  ON u.id  = o.owner_id
        LEFT JOIN " . sys_table('users') . " cb ON cb.id = o.changed_by
        WHERE o.table_name = \$1 AND o.record_id = \$2
        ORDER BY o.changed_at DESC
    ";

    $result = pg_query_params($conn, $sql, [$table, $recordId]);
    if (!$result) {
        error_log('[api_owners owners_action_history] ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $rows = [];
    while ($row = pg_fetch_assoc($result)) {
        $rows[] = [
            'owner_id'        => $row['owner_id'] !== null ? (int)$row['owner_id'] : null,
            'username'        => $row['username'],
            'changed_at'      => $row['changed_at'],
            'changed_by_name' => $row['changed_by_name'],
        ];
    }

    jsonSuccess(['history' => $rows]);
}

function owners_action_editors($conn): void
{
    requireLogin();

    $sql = "
        SELECT id, username
        FROM " . sys_table('users') . "
        WHERE is_active = true AND role IN ('editor', 'admin')
        ORDER BY username
    ";

    $result = pg_query($conn, $sql);
    if (!$result) {
        error_log('[api_owners owners_action_editors] ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $users = [];
    while ($row = pg_fetch_assoc($result)) {
        $users[] = ['id' => (int)$row['id'], 'username' => $row['username']];
    }

    jsonSuccess(['users' => $users]);
}

function owners_action_mine($conn): void
{
    requireLogin();

    $userId = (int)($_SESSION['user_id'] ?? 0);

    $sql = "
        SELECT table_name, record_id, changed_at
        FROM " . sys_table('record_owners') . "
        WHERE owner_id = \$1 AND is_current = true
        ORDER BY table_name, changed_at DESC, record_id DESC
    ";

    $result = pg_query_params($conn, $sql, [$userId]);
    if (!$result) {
        error_log('[api_owners owners_action_mine] ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    require_once __DIR__ . '/../../includes/config_store.php';
    $userRecordsCfg  = config_get('user_records') ?? [];
    $configuredCols  = is_array($userRecordsCfg['columns'] ?? null) ? $userRecordsCfg['columns'] : [];
    $limit           = (int)($userRecordsCfg['limit'] ?? 20);

    $byTable    = [];
    $assignedAt = [];
    while ($row = pg_fetch_assoc($result)) {
        $tableName = $row['table_name'];
        if ($limit > 0 && count($byTable[$tableName] ?? []) >= $limit) {
            continue;
        }
        $recordId = (int)$row['record_id'];
        $byTable[$tableName][] = $recordId;
        $assignedAt[$tableName . '#' . $recordId] = $row['changed_at'];
    }

    $schema = config_get('schema') ?? [];

    $records = [];
    foreach ($byTable as $tableName => $ids) {
        $tableCfg = $schema['tables'][$tableName] ?? null;

        if ($tableCfg === null || !empty($tableCfg['hidden'])) {
            continue;
        }

        if (!user_can_access_table($tableName)) {
            continue;
        }

        $pgSchema = $tableCfg['schema'] ?? 'public';
        $arrParam = '{' . implode(',', $ids) . '}';
        $labelSql = record_label_sql($tableCfg, $configuredCols[$tableName] ?? []);

        $rowsSql = sprintf(
            'SELECT id, %s AS label FROM %s.%s WHERE id = ANY($1::int[])',
            $labelSql,
            pg_ident($pgSchema),
            pg_ident($tableName)
        );

        $rowsResult = pg_query_params($conn, $rowsSql, [$arrParam]);
        if (!$rowsResult) {
            error_log('[api_owners owners_action_mine] ' . pg_last_error($conn));
            continue;
        }

        $tableDisplay = to_display_name($tableCfg);
        while ($row = pg_fetch_assoc($rowsResult)) {
            $recordId = (int)$row['id'];
            $label    = trim((string)($row['label'] ?? ''));
            $records[] = [
                'table'         => $tableName,
                'table_display' => $tableDisplay,
                'id'            => $recordId,
                'label'         => $label !== '' ? $label : ('#' . $recordId),
                'assigned_at'   => $assignedAt[$tableName . '#' . $recordId] ?? null,
            ];
        }
    }

    usort($records, fn($first, $second) => strcmp((string)$second['assigned_at'], (string)$first['assigned_at']));

    jsonSuccess(['records' => $records]);
}

function owners_action_mass_set($conn, array $body): void
{
    requireWrite();
    os_require_csrf('body', $body);

    $table   = validatedTable(trim($body['table'] ?? ''));
    $ownerId = (int)($body['owner_id'] ?? 0);

    if ($ownerId <= 0) {
        jsonError('owner_id must be a positive integer.', 400);
    }

    $checkResult = pg_query_params(
        $conn,
        "SELECT id FROM " . sys_table('users') .
        " WHERE id = \$1 AND is_active = true AND role IN ('editor', 'admin')",
        [$ownerId]
    );
    if (!$checkResult || pg_num_rows($checkResult) === 0) {
        jsonError('Invalid owner: user not found or does not have editor access.', 400);
    }

    $rawIds = $body['row_ids'] ?? [];
    if (!is_array($rawIds)) {
        jsonError('row_ids must be an array.', 400);
    }
    $rowIds = [];
    foreach ($rawIds as $id) {
        $validatedId = filter_var($id, FILTER_VALIDATE_INT);
        if ($validatedId !== false && $validatedId > 0) {
            $rowIds[] = $validatedId;
        }
    }
    $rowIds = array_values(array_unique($rowIds));

    if (empty($rowIds)) {
        jsonError('No rows selected.', 400);
    }

    $changedBy = (int)$_SESSION['user_id'];
    $ownersTable         = sys_table('record_owners');
    $arrParam  = '{' . implode(',', array_map('intval', $rowIds)) . '}';

    @pg_query($conn, 'BEGIN');

    $result = @pg_query_params(
        $conn,
        "UPDATE $ownersTable SET is_current = false
         WHERE table_name = \$1 AND record_id = ANY(\$2::int[]) AND is_current = true",
        [$table, $arrParam]
    );
    if (!$result) {
        @pg_query($conn, 'ROLLBACK');
        jsonError('Database error.', 500);
    }

    $res2 = @pg_query_params(
        $conn,
        "INSERT INTO $ownersTable (table_name, record_id, owner_id, changed_by, is_current)
         SELECT \$1, unnest(\$2::int[]), \$3, \$4, true",
        [$table, $arrParam, $ownerId, $changedBy]
    );
    if (!$res2) {
        @pg_query($conn, 'ROLLBACK');
        jsonError('Database error.', 500);
    }

    $affected = pg_affected_rows($res2);
    @pg_query($conn, 'COMMIT');

    log_user_action($conn, $changedBy, 'MASS_OWNER', $table, null);

    jsonSuccess(['updated' => $affected]);
}

function owners_action_set($conn, array $body): void
{
    requireWrite();
    os_require_csrf('body', $body);

    $table    = validatedTable(trim($body['table'] ?? ''));
    $recordId = (int)($body['record_id'] ?? 0);
    $ownerId  = (int)($body['owner_id'] ?? 0);

    if ($recordId <= 0) {
        jsonError('record_id must be a positive integer.', 400);
    }
    if ($ownerId <= 0) {
        jsonError('owner_id must be a positive integer.', 400);
    }

    $checkResult = pg_query_params(
        $conn,
        "SELECT id FROM " . sys_table('users') . " WHERE id = \$1 AND is_active = true AND role IN ('editor', 'admin')",
        [$ownerId]
    );
    if (!$checkResult || pg_num_rows($checkResult) === 0) {
        jsonError('Invalid owner: user not found or does not have editor access.', 400);
    }

    $changedBy = (int)$_SESSION['user_id'];
    set_record_owner($conn, $table, $recordId, $ownerId, $changedBy);

    jsonSuccess(['changed' => true]);
}
