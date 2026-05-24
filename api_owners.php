<?php

declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/api_helpers.php';
header('Content-Type: application/json; charset=utf-8');
send_security_headers();
start_session();

$conn = db_connect();

function jsonError(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function jsonSuccess(array $data = [], int $code = 200): void
{
    http_response_code($code);
    $data['success'] = true;
    echo json_encode($data);
    exit;
}

function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        jsonError('Unauthorised', 401);
    }
}

function requireWrite(): void
{
    requireLogin();
    $role = $_SESSION['role'] ?? '';
    if ($role !== 'editor' && $role !== 'admin') {
        jsonError('Forbidden: read-only access', 403);
    }
}

function requireCsrf(array $body): void
{
    $token   = $body['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';
    if (!$session || !hash_equals($session, (string)$token)) {
        jsonError('Invalid CSRF token.', 403);
    }
}

function validatedTable(string $table): string
{
    if ($table === '') {
        jsonError('table is required.', 400);
    }
    $schema = json_decode(
        (string)file_get_contents(__DIR__ . '/config/schema.json'),
        true
    );
    if (!isset($schema['tables'][$table])) {
        jsonError('Unknown table.', 400);
    }
    return $table;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $body   = [];
    $action = '';

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
        'get'      => actionGet($conn),
        'history'  => actionHistory($conn),
        'editors'  => actionEditors($conn),
        'set'      => actionSet($conn, $body),
        'mass_set' => actionMassSet($conn, $body),
        default    => jsonError("Unknown action: {$action}", 400),
    };
} catch (Throwable $e) {
    error_log('[api_owners] ' . $e->getMessage());
    jsonError('Server error.', 500);
}

function actionGet($conn): void
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

    $res = pg_query_params($conn, $sql, [$table, $recordId]);
    if (!$res) {
        error_log('[api_owners actionGet] ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $row = pg_fetch_assoc($res);
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

function actionHistory($conn): void
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

    $res = pg_query_params($conn, $sql, [$table, $recordId]);
    if (!$res) {
        error_log('[api_owners actionHistory] ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $rows = [];
    while ($row = pg_fetch_assoc($res)) {
        $rows[] = [
            'owner_id'        => $row['owner_id'] !== null ? (int)$row['owner_id'] : null,
            'username'        => $row['username'],
            'changed_at'      => $row['changed_at'],
            'changed_by_name' => $row['changed_by_name'],
        ];
    }

    jsonSuccess(['history' => $rows]);
}

function actionEditors($conn): void
{
    requireLogin();

    $sql = "
        SELECT id, username
        FROM " . sys_table('users') . "
        WHERE is_active = true AND role IN ('editor', 'admin')
        ORDER BY username
    ";

    $res = pg_query($conn, $sql);
    if (!$res) {
        error_log('[api_owners actionEditors] ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $users = [];
    while ($row = pg_fetch_assoc($res)) {
        $users[] = ['id' => (int)$row['id'], 'username' => $row['username']];
    }

    jsonSuccess(['users' => $users]);
}

function actionMassSet($conn, array $body): void
{
    requireWrite();
    requireCsrf($body);

    $table   = validatedTable(trim($body['table'] ?? ''));
    $ownerId = (int)($body['owner_id'] ?? 0);

    if ($ownerId <= 0) {
        jsonError('owner_id must be a positive integer.', 400);
    }

    $checkRes = pg_query_params(
        $conn,
        "SELECT id FROM " . sys_table('users') .
        " WHERE id = \$1 AND is_active = true AND role IN ('editor', 'admin')",
        [$ownerId]
    );
    if (!$checkRes || pg_num_rows($checkRes) === 0) {
        jsonError('Invalid owner: user not found or does not have editor access.', 400);
    }

    $rawIds = $body['row_ids'] ?? [];
    if (!is_array($rawIds)) {
        jsonError('row_ids must be an array.', 400);
    }
    $rowIds = [];
    foreach ($rawIds as $id) {
        $int = filter_var($id, FILTER_VALIDATE_INT);
        if ($int !== false && $int > 0) {
            $rowIds[] = $int;
        }
    }
    $rowIds = array_values(array_unique($rowIds));

    if (empty($rowIds)) {
        jsonError('No rows selected.', 400);
    }

    $changedBy = (int)$_SESSION['user_id'];
    $t         = sys_table('record_owners');
    $arrParam  = '{' . implode(',', array_map('intval', $rowIds)) . '}';

    @pg_query($conn, 'BEGIN');

    $res = @pg_query_params(
        $conn,
        "UPDATE $t SET is_current = false
         WHERE table_name = \$1 AND record_id = ANY(\$2::int[]) AND is_current = true",
        [$table, $arrParam]
    );
    if (!$res) {
        @pg_query($conn, 'ROLLBACK');
        jsonError('Database error.', 500);
    }

    $res2 = @pg_query_params(
        $conn,
        "INSERT INTO $t (table_name, record_id, owner_id, changed_by, is_current)
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

function actionSet($conn, array $body): void
{
    requireWrite();
    requireCsrf($body);

    $table    = validatedTable(trim($body['table'] ?? ''));
    $recordId = (int)($body['record_id'] ?? 0);
    $ownerId  = (int)($body['owner_id'] ?? 0);

    if ($recordId <= 0) {
        jsonError('record_id must be a positive integer.', 400);
    }
    if ($ownerId <= 0) {
        jsonError('owner_id must be a positive integer.', 400);
    }

    // Verify the new owner exists and has editor or admin role.
    $checkRes = pg_query_params(
        $conn,
        "SELECT id FROM " . sys_table('users') . " WHERE id = \$1 AND is_active = true AND role IN ('editor', 'admin')",
        [$ownerId]
    );
    if (!$checkRes || pg_num_rows($checkRes) === 0) {
        jsonError('Invalid owner: user not found or does not have editor access.', 400);
    }

    $changedBy = (int)$_SESSION['user_id'];
    set_record_owner($conn, $table, $recordId, $ownerId, $changedBy);

    jsonSuccess(['changed' => true]);
}
