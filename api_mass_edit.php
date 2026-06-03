<?php

declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/api_helpers.php';

header('Content-Type: application/json; charset=utf-8');
send_security_headers();
start_session();
// Hard session-lifetime + User-Agent enforcement (centralised in session.php).
enforce_session_json();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$role = $_SESSION['role'] ?? 'viewer';
if ($role !== 'editor') {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden: editor role required']));
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        exit(json_encode(['error' => 'CSRF token mismatch']));
    }
}

$action = $_GET['action'] ?? '';
$conn   = db_connect();

$schemaJson = file_get_contents(__DIR__ . '/config/schema.json');
$schema     = json_decode($schemaJson, true, 512, JSON_THROW_ON_ERROR);

// Validate table + column against schema. Returns [$tableCfg, $tableName, $colCfg, $colSql, $tblSql].
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

// Sanitize row_ids to a list of positive integers. Rejects anything else.
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

// Build a PostgreSQL integer array literal from a sanitized id list: {1,2,3}
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

    [, , , $colSql, $tblSql] = validateTableColumn($body, $schema);

    $arrParam = pgIntArray($rowIds);

    $countRes = @pg_query_params(
        $conn,
        "SELECT COUNT(*) FROM {$tblSql} WHERE id = ANY(\$1::int[])",
        [$arrParam]
    );
    if (!$countRes) {
        http_response_code(500);
        exit(json_encode(['error' => pg_last_error($conn)]));
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
    if (!$rowRes) {
        http_response_code(500);
        exit(json_encode(['error' => pg_last_error($conn)]));
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

    // value: PHP null → SQL NULL; string → the value (PG handles type cast)
    $value = array_key_exists('value', $body)
        ? ($body['value'] === null ? null : (string)$body['value'])
        : null;

    [, $tableName, , $colSql, $tblSql] = validateTableColumn($body, $schema);

    $arrParam = pgIntArray($rowIds);

    @pg_query($conn, 'BEGIN');

    $res = @pg_query_params(
        $conn,
        "UPDATE {$tblSql} SET {$colSql} = \$2 WHERE id = ANY(\$1::int[])",
        [$arrParam, $value]
    );

    if (!$res) {
        @pg_query($conn, 'ROLLBACK');
        http_response_code(500);
        exit(json_encode(['error' => pg_last_error($conn)]));
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

    // Build column list — exclude id and virtual columns
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

    @pg_query($conn, 'BEGIN');

    $res = @pg_query_params(
        $conn,
        "INSERT INTO {$tblSql} ({$colIdents})
         SELECT {$colIdents} FROM {$tblSql}
         WHERE id = ANY(\$1::int[])
         RETURNING id",
        [$arrParam]
    );

    if (!$res) {
        @pg_query($conn, 'ROLLBACK');
        $pgErr    = pg_last_error($conn);
        $isUnique = stripos($pgErr, 'unique') !== false || stripos($pgErr, 'unikaln') !== false;
        http_response_code(422);
        exit(json_encode([
            'error'        => $isUnique ? 'unique_violation' : $pgErr,
            'is_unique'    => $isUnique,
        ]));
    }

    $duplicated = pg_num_rows($res);
    pg_free_result($res);
    @pg_query($conn, 'COMMIT');

    $uid = (int)$_SESSION['user_id'];
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

    $schemaName = $tableCfg['schema'] ?? 'public';
    $tblSql     = pg_ident($schemaName) . '.' . pg_ident($tableName);
    $arrParam   = pgIntArray($rowIds);

    @pg_query($conn, 'BEGIN');

    $res = @pg_query_params(
        $conn,
        "DELETE FROM {$tblSql} WHERE id = ANY(\$1::int[])",
        [$arrParam]
    );

    if (!$res) {
        @pg_query($conn, 'ROLLBACK');
        http_response_code(500);
        exit(json_encode(['error' => pg_last_error($conn)]));
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
