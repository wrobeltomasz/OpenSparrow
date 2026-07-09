<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// api/comments.php — Comments module API (discussion threads attached to records)
// Auth gate: session + UA enforcement + CSRF on POST; JSON responses via jsonError()/jsonSuccess()
// match() action routing: list, add, delete, counts — comments keyed by (related_table, related_id), table validated against schema.json
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
