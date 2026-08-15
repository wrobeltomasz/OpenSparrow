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
    'list'   => fn() => comments_action_list($conn),
    'mine'   => fn() => comments_action_mine($conn),
    'add'    => fn() => comments_action_add($conn, $body),
    'delete' => fn() => comments_action_delete($conn, $body),
    'counts' => fn() => comments_action_counts($conn),
], 'api_comments');

function comments_action_list($conn): void
{
    requireLogin();
    $relatedTable = validatedTable(trim($_GET['related_table'] ?? ''), 'related_table');
    $relatedId    = (int)($_GET['related_id'] ?? 0);
    $limit        = isset($_GET['limit']) ? min(COMMENTS_PAGE_LIMIT_MAX, max(1, (int)$_GET['limit'])) : null;
    if ($relatedId <= 0) {
        jsonError('related_id must be a positive integer.', 400);
    }

    $orderDirection    = $limit ? 'DESC' : 'ASC';
    $limitClause = $limit ? " LIMIT {$limit}" : '';
    $sql = "
        SELECT
            c.id,
            c.body,
            c.created_at,
            c.deleted_at,
            c.user_id,
            u.username,
            u.avatar_id
        FROM " . sys_table('comments') . " c
        LEFT JOIN " . sys_table('users') . " u ON u.id = c.user_id
        WHERE c.related_table = \$1 AND c.related_id = \$2
        ORDER BY c.created_at {$orderDirection}{$limitClause}
    ";
    $result = pg_query_params($conn, $sql, [$relatedTable, $relatedId]);
    if (!$result) {
        error_log('api_comments comments_action_list failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $comments = [];
    while ($row = pg_fetch_assoc($result)) {
        $row['avatar_id'] = $row['avatar_id'] !== null ? (int)$row['avatar_id'] : null;
        $comments[] = $row;
    }

    jsonSuccess(['comments' => $comments]);
}

function comments_action_mine($conn): void
{
    requireLogin();

    $userId = (int)($_SESSION['user_id'] ?? 0);

    $sql = 'SELECT id, body, related_table, related_id, created_at
        FROM ' . sys_table('comments') . '
        WHERE user_id = $1 AND deleted_at IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT ' . COMMENTS_MINE_LIMIT;

    $result = pg_query_params($conn, $sql, [$userId]);
    if (!$result) {
        error_log('api_comments comments_action_mine failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $rows   = pg_fetch_all($result) ?: [];
    $idsBy  = [];
    foreach ($rows as $row) {
        $idsBy[$row['related_table']][] = (int)$row['related_id'];
    }

    require_once __DIR__ . '/../../includes/config_store.php';
    $schema         = config_get('schema') ?? [];
    $userRecordsCfg = config_get('user_records') ?? [];
    $configuredColumns = is_array($userRecordsCfg['columns'] ?? null) ? $userRecordsCfg['columns'] : [];

    $resolved = [];
    foreach ($idsBy as $tableName => $ids) {
        $tableCfg = $schema['tables'][$tableName] ?? null;

        if ($tableCfg === null || !empty($tableCfg['hidden'])) {
            continue;
        }

        if (!user_can_access_table($tableName)) {
            continue;
        }

        $rowsSql = sprintf(
            'SELECT id, %s AS label FROM %s.%s WHERE id = ANY($1::int[])',
            record_label_sql($tableCfg, $configuredColumns[$tableName] ?? []),
            pg_ident($tableCfg['schema'] ?? 'public'),
            pg_ident($tableName)
        );

        $labelResult = pg_query_params($conn, $rowsSql, ['{' . implode(',', array_unique($ids)) . '}']);
        if (!$labelResult) {
            error_log('api_comments comments_action_mine label lookup failed: ' . pg_last_error($conn));
            continue;
        }

        $labels = [];
        while ($row = pg_fetch_assoc($labelResult)) {
            $label = trim((string)($row['label'] ?? ''));
            $labels[(int)$row['id']] = $label !== '' ? $label : ('#' . $row['id']);
        }

        $resolved[$tableName] = [
            'display' => to_display_name($tableCfg),
            'labels'  => $labels,
        ];
    }

    $comments = [];
    foreach ($rows as $row) {
        $tableName = $row['related_table'];
        if (!isset($resolved[$tableName])) {
            continue;
        }
        $recordId = (int)$row['related_id'];
        $comments[] = [
            'id'            => (int)$row['id'],
            'body'          => $row['body'],
            'related_table' => $tableName,
            'related_id'    => $recordId,
            'table_display' => $resolved[$tableName]['display'],

            'record_label'  => $resolved[$tableName]['labels'][$recordId] ?? ('#' . $recordId),
            'created_at'    => $row['created_at'],
        ];
    }

    jsonSuccess(['comments' => $comments]);
}

function comments_action_add($conn, array $body): void
{
    requireWrite();
    os_require_csrf('body', $body);
    $relatedTable = validatedTable(trim($body['related_table'] ?? ''), 'related_table');
    $relatedId    = (int)($body['related_id'] ?? 0);
    $rawBody      = trim($body['body'] ?? '');
    if ($relatedId <= 0) {
        jsonError('related_id must be a positive integer.', 400);
    }
    if ($rawBody === '') {
        jsonError('Comment body cannot be empty.', 400);
    }
    if (mb_strlen($rawBody) > 4000) {
        jsonError('Comment exceeds maximum length of 4000 characters.', 400);
    }

    $userId = (int)$_SESSION['user_id'];
    $sql = "
        INSERT INTO " . sys_table('comments') . "
            (related_table, related_id, user_id, body)
        VALUES (\$1, \$2, \$3, \$4)
        RETURNING id, created_at
    ";
    $result = pg_query_params($conn, $sql, [$relatedTable, $relatedId, $userId, $rawBody]);
    if (!$result) {
        error_log('api_comments comments_action_add failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $inserted = pg_fetch_assoc($result);
    log_user_action($conn, $userId, 'COMMENT_ADD', $relatedTable, $relatedId);

    $fetchSql = "
        SELECT c.id, c.body, c.created_at, c.deleted_at, c.user_id,
               u.username, u.avatar_id
        FROM " . sys_table('comments') . " c
        LEFT JOIN " . sys_table('users') . " u ON u.id = c.user_id
        WHERE c.id = \$1
    ";
    $fetchResult = pg_query_params($conn, $fetchSql, [(int)$inserted['id']]);
    $comment  = pg_fetch_assoc($fetchResult);
    if ($comment) {
        $comment['avatar_id'] = $comment['avatar_id'] !== null ? (int)$comment['avatar_id'] : null;
    }

    jsonSuccess(['comment' => $comment], 201);
}

function comments_action_delete($conn, array $body): void
{
    requireLogin();
    os_require_csrf('body', $body);
    $id     = (int)($body['id'] ?? 0);
    $userId = (int)$_SESSION['user_id'];
    $role   = $_SESSION['role'] ?? 'editor';
    if ($id <= 0) {
        jsonError('id is required.', 400);
    }

    $fetchSql = "SELECT user_id, related_table, related_id FROM " . sys_table('comments')
        . " WHERE id = \$1 AND deleted_at IS NULL";
    $fetchResult = pg_query_params($conn, $fetchSql, [$id]);
    if (!$fetchResult || pg_num_rows($fetchResult) === 0) {
        jsonError('Comment not found.', 404);
    }

    $row = pg_fetch_assoc($fetchResult);
    if ($role !== 'editor' && (int)$row['user_id'] !== $userId) {
        jsonError('Forbidden: you can only delete your own comments.', 403);
    }

    $sql = "UPDATE " . sys_table('comments') . " SET deleted_at = NOW() WHERE id = \$1 AND deleted_at IS NULL";
    $result = pg_query_params($conn, $sql, [$id]);
    if (!$result) {
        error_log('api_comments comments_action_delete failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    log_user_action($conn, $userId, 'COMMENT_DELETE', $row['related_table'], (int)$row['related_id']);
    jsonSuccess(['deleted' => true]);
}

function comments_action_counts($conn): void
{
    requireLogin();
    $relatedTable = validatedTable(trim($_GET['related_table'] ?? ''), 'related_table');
    $rawIds       = trim($_GET['related_ids'] ?? '');
    if ($rawIds === '') {
        jsonSuccess(['counts' => []]);
    }

    $ids = array_values(array_filter(array_map('intval', explode(',', $rawIds)), fn($id) => $id > 0));
    if (empty($ids)) {
        jsonSuccess(['counts' => []]);
    }

    $placeholders = implode(', ', array_map(fn($placeholderIndex) => '$' . ($placeholderIndex + 2), array_keys($ids)));
    $params       = array_merge([$relatedTable], $ids);
    $sql = "
        SELECT related_id, COUNT(*) AS cnt
        FROM " . sys_table('comments') . "
        WHERE related_table = \$1 AND related_id IN ($placeholders) AND deleted_at IS NULL
        GROUP BY related_id
    ";
    $result = pg_query_params($conn, $sql, $params);
    if (!$result) {
        error_log('api_comments comments_action_counts failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $counts = [];
    while ($row = pg_fetch_assoc($result)) {
        $counts[(int)$row['related_id']] = (int)$row['cnt'];
    }

    jsonSuccess(['counts' => $counts]);
}
