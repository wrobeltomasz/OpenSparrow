<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.
//
// api/comments.php — Comments module API (discussion threads attached to records)
// Auth gate: session + UA enforcement + CSRF on POST; JSON responses via jsonError()/jsonSuccess()
// match() action routing: list, mine, add, delete, counts — comments keyed by (related_table, related_id), table validated against schema.json
// Parameterized queries; sys_table('comments')

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

// csrf=manual: mutating actions validate the body token via os_require_csrf() themselves
$conn = os_api_bootstrap(['csrf' => 'manual']);
// jsonError(), jsonSuccess(), requireLogin(), requireWrite() and validatedTable()
// are shared via includes/api_helpers.php

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    $body   = [];
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($method === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';
    }

    if ($action === '') {
        jsonError('Missing action.', 400);
    }

    match ($action) {
        'list'   => actionList($conn),
        'mine'   => actionMine($conn),
        'add'    => actionAdd($conn, $body),
        'delete' => actionDelete($conn, $body),
        'counts' => actionCounts($conn),
        default  => jsonError("Unknown action: {$action}", 400),
    };
} catch (Throwable $e) {
    error_log('[api_comments] ' . $e->getMessage());
    jsonError('Internal server error.', 500);
}

function actionList($conn): void
{
    requireLogin();
    $relatedTable = validatedTable(trim($_GET['related_table'] ?? ''), 'related_table');
    $relatedId    = (int)($_GET['related_id'] ?? 0);
    $limit        = isset($_GET['limit']) ? min(COMMENTS_PAGE_LIMIT_MAX, max(1, (int)$_GET['limit'])) : null;
    if ($relatedId <= 0) {
        jsonError('related_id must be a positive integer.', 400);
    }

    // When a limit is requested (preview), return newest-first so the caller
    // gets the most recent N without client-side sorting.
    $orderDir    = $limit ? 'DESC' : 'ASC';
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
        ORDER BY c.created_at {$orderDir}{$limitClause}
    ";
    $res = pg_query_params($conn, $sql, [$relatedTable, $relatedId]);
    if (!$res) {
        error_log('api_comments actionList failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $comments = [];
    while ($row = pg_fetch_assoc($res)) {
        $row['avatar_id'] = $row['avatar_id'] !== null ? (int)$row['avatar_id'] : null;
        $comments[] = $row;
    }

    jsonSuccess(['comments' => $comments]);
}

// "My comments" (avatar menu panel) — flat, newest-first list of the comments the logged-in
// user authored, each resolved to its record's display label so the panel can link straight
// back to the record's comment tab. Label heuristic is shared with the "My records" panel
// (record_label_sql() in api_helpers.php).
function actionMine($conn): void
{
    requireLogin();

    $userId = (int)($_SESSION['user_id'] ?? 0);

    $sql = 'SELECT id, body, related_table, related_id, created_at
        FROM ' . sys_table('comments') . '
        WHERE user_id = $1 AND deleted_at IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT ' . COMMENTS_MINE_LIMIT;

    $res = pg_query_params($conn, $sql, [$userId]);
    if (!$res) {
        error_log('api_comments actionMine failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $rows   = pg_fetch_all($res) ?: [];
    $idsBy  = [];
    foreach ($rows as $row) {
        $idsBy[$row['related_table']][] = (int)$row['related_id'];
    }

    require_once __DIR__ . '/../../includes/config_store.php';
    $schema         = config_get('schema') ?? [];
    $userRecordsCfg = config_get('user_records') ?? [];
    $configuredCols = is_array($userRecordsCfg['columns'] ?? null) ? $userRecordsCfg['columns'] : [];

    // table => ['display' => string, 'labels' => [record_id => label]]
    $resolved = [];
    foreach ($idsBy as $tableName => $ids) {
        $tableCfg = $schema['tables'][$tableName] ?? null;
        // Comments can outlive their table (renamed/removed from the schema editor).
        if ($tableCfg === null || !empty($tableCfg['hidden'])) {
            continue;
        }

        $rowsSql = sprintf(
            'SELECT id, %s AS label FROM %s.%s WHERE id = ANY($1::int[])',
            record_label_sql($tableCfg, $configuredCols[$tableName] ?? []),
            pg_ident($tableCfg['schema'] ?? 'public'),
            pg_ident($tableName)
        );

        $labelRes = pg_query_params($conn, $rowsSql, ['{' . implode(',', array_unique($ids)) . '}']);
        if (!$labelRes) {
            error_log('api_comments actionMine label lookup failed: ' . pg_last_error($conn));
            continue;
        }

        $labels = [];
        while ($r = pg_fetch_assoc($labelRes)) {
            $label = trim((string)($r['label'] ?? ''));
            $labels[(int)$r['id']] = $label !== '' ? $label : ('#' . $r['id']);
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
            // A comment can outlive its record when the row was hard-deleted.
            'record_label'  => $resolved[$tableName]['labels'][$recordId] ?? ('#' . $recordId),
            'created_at'    => $row['created_at'],
        ];
    }

    jsonSuccess(['comments' => $comments]);
}

function actionAdd($conn, array $body): void
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
    $res = pg_query_params($conn, $sql, [$relatedTable, $relatedId, $userId, $rawBody]);
    if (!$res) {
        error_log('api_comments actionAdd failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $inserted = pg_fetch_assoc($res);
    log_user_action($conn, $userId, 'COMMENT_ADD', $relatedTable, $relatedId);
// Return the full comment row including user info for immediate render
    $fetchSql = "
        SELECT c.id, c.body, c.created_at, c.deleted_at, c.user_id,
               u.username, u.avatar_id
        FROM " . sys_table('comments') . " c
        LEFT JOIN " . sys_table('users') . " u ON u.id = c.user_id
        WHERE c.id = \$1
    ";
    $fetchRes = pg_query_params($conn, $fetchSql, [(int)$inserted['id']]);
    $comment  = pg_fetch_assoc($fetchRes);
    if ($comment) {
        $comment['avatar_id'] = $comment['avatar_id'] !== null ? (int)$comment['avatar_id'] : null;
    }

    jsonSuccess(['comment' => $comment], 201);
}

function actionDelete($conn, array $body): void
{
    requireLogin();
    os_require_csrf('body', $body);
    $id     = (int)($body['id'] ?? 0);
    $userId = (int)$_SESSION['user_id'];
    $role   = $_SESSION['role'] ?? 'editor';
    if ($id <= 0) {
        jsonError('id is required.', 400);
    }

    // Fetch the comment to check ownership
    $fetchSql = "SELECT user_id, related_table, related_id FROM " . sys_table('comments') . " WHERE id = \$1 AND deleted_at IS NULL";
    $fetchRes = pg_query_params($conn, $fetchSql, [$id]);
    if (!$fetchRes || pg_num_rows($fetchRes) === 0) {
        jsonError('Comment not found.', 404);
    }

    $row = pg_fetch_assoc($fetchRes);
    if ($role !== 'editor' && (int)$row['user_id'] !== $userId) {
        jsonError('Forbidden: you can only delete your own comments.', 403);
    }

    $sql = "UPDATE " . sys_table('comments') . " SET deleted_at = NOW() WHERE id = \$1 AND deleted_at IS NULL";
    $res = pg_query_params($conn, $sql, [$id]);
    if (!$res) {
        error_log('api_comments actionDelete failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    log_user_action($conn, $userId, 'COMMENT_DELETE', $row['related_table'], (int)$row['related_id']);
    jsonSuccess(['deleted' => true]);
}

function actionCounts($conn): void
{
    requireLogin();
    $relatedTable = validatedTable(trim($_GET['related_table'] ?? ''), 'related_table');
    $rawIds       = trim($_GET['related_ids'] ?? '');
    if ($rawIds === '') {
        jsonSuccess(['counts' => []]);
    }

    // Parse and validate IDs — integers only
    $ids = array_values(array_filter(array_map('intval', explode(',', $rawIds)), fn($id) => $id > 0));
    if (empty($ids)) {
        jsonSuccess(['counts' => []]);
    }

    // Build safe parameterized IN clause
    $placeholders = implode(', ', array_map(fn($i) => '$' . ($i + 2), array_keys($ids)));
    $params       = array_merge([$relatedTable], $ids);
    $sql = "
        SELECT related_id, COUNT(*) AS cnt
        FROM " . sys_table('comments') . "
        WHERE related_table = \$1 AND related_id IN ($placeholders) AND deleted_at IS NULL
        GROUP BY related_id
    ";
    $res = pg_query_params($conn, $sql, $params);
    if (!$res) {
        error_log('api_comments actionCounts failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $counts = [];
    while ($row = pg_fetch_assoc($res)) {
        $counts[(int)$row['related_id']] = (int)$row['cnt'];
    }

    jsonSuccess(['counts' => $counts]);
}
