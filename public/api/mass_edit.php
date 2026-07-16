<?php

declare(strict_types=1);

// api/mass_edit.php — Mass record operations API (editor-only)
// Auth gate: session + UA enforcement; requires editor role; CSRF on POST
// POST actions: mass_edit_preview, mass_edit_apply, mass_duplicate, mass_delete — operate on a selected set of record IDs, preview before apply
// Reads config/schema.json + config/mysql_gateway.json; parameterized queries; sys_table()

require_once __DIR__ . '/../../includes/bootstrap.php';

// Auth gate + editor-role gate + header CSRF on POST; returns an open DB connection
$conn = os_api_bootstrap(['role' => 'editor']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

require_once __DIR__ . '/../../includes/config_store.php';
$schema = config_get('schema') ?? ['tables' => []];

$mysqlGatewayPath   = __DIR__ . '/../../config/mysql_gateway.json';
$mysqlGatewayTables = [];
if (file_exists($mysqlGatewayPath)) {
    $mgCfg              = json_decode(file_get_contents($mysqlGatewayPath), true);
    $mysqlGatewayTables = is_array($mgCfg) ? ($mgCfg['mysql_tables'] ?? []) : [];
}

// MySQL Gateway PDO + identifier quoting live in the shared includes/mysql.php module
require_once __DIR__ . '/../../includes/mysql.php';

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

    [$tableCfg, $tableName, , $colSql, $tblSql] = validateTableColumn($body, $schema);

    if (in_array($tableName, $mysqlGatewayTables, true)) {
        $pdo = mysql_pdo('mass_edit');
        if ($pdo === null) {
            http_response_code(503);
            exit(json_encode(['error' => 'MySQL connection unavailable']));
        }
        $colName  = (string)($body['column'] ?? '');
        $mysqlPk  = (string)($tableCfg['mysql_pk'] ?? 'id');
        $pkBt     = mysql_bt($mysqlPk);
        $colBt    = mysql_bt($colName);
        $tblBt    = mysql_bt(MYSQL_DB) . '.' . mysql_bt($tableName);
        $inList   = implode(',', array_fill(0, count($rowIds), '?'));
        try {
            $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM {$tblBt} WHERE {$pkBt} IN ({$inList})");
            $stmtCnt->execute($rowIds);
            $count   = (int)$stmtCnt->fetchColumn();
            $stmtRows = $pdo->prepare(
                "SELECT {$pkBt} AS id, {$colBt} AS current_val FROM {$tblBt}"
                . " WHERE {$pkBt} IN ({$inList}) ORDER BY {$pkBt} LIMIT 10"
            );
            $stmtRows->execute($rowIds);
            $mysqlRows = $stmtRows->fetchAll();
        } catch (\PDOException $e) {
            http_response_code(500);
            exit(json_encode(['error' => 'MySQL query failed.']));
        }
        $rows = array_map(
            fn($r) => ['id' => (int)$r['id'], 'current' => $r['current_val']],
            $mysqlRows
        );
        exit(json_encode(['count' => $count, 'rows' => $rows]));
    }

    $arrParam = pgIntArray($rowIds);

    if (!empty($tableCfg['owner_restricted'])) {
        $uid      = (int)$_SESSION['user_id'];
        $ownerSql = owner_restriction_sql('id', 2, 3);

        $countRes = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$tblSql} WHERE id = ANY(\$1::int[]){$ownerSql}",
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
            "SELECT id, {$colSql} AS current_val
             FROM {$tblSql}
             WHERE id = ANY(\$1::int[]){$ownerSql}
             ORDER BY id
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

    // value: PHP null → SQL NULL; string → the value (PG handles type cast)
    $value = array_key_exists('value', $body)
        ? ($body['value'] === null ? null : (string)$body['value'])
        : null;

    [$tableCfg, $tableName, $colCfg, $colSql, $tblSql] = validateTableColumn($body, $schema);

    // Server-side validation_regexp enforcement (client check is advisory)
    if (($regexpError = validate_column_regexp($colCfg, $value)) !== null) {
        http_response_code(422);
        exit(json_encode(['error' => $regexpError]));
    }

    if (in_array($tableName, $mysqlGatewayTables, true)) {
        $pdo = mysql_pdo('mass_edit');
        if ($pdo === null) {
            http_response_code(503);
            exit(json_encode(['error' => 'MySQL connection unavailable']));
        }
        $colName = (string)($body['column'] ?? '');
        $mysqlPk = (string)($tableCfg['mysql_pk'] ?? 'id');
        $pkBt    = mysql_bt($mysqlPk);
        $colBt   = mysql_bt($colName);
        $tblBt   = mysql_bt(MYSQL_DB) . '.' . mysql_bt($tableName);
        $inList  = implode(',', array_fill(0, count($rowIds), '?'));
        try {
            $params = array_merge([$value], $rowIds);
            $stmt   = $pdo->prepare("UPDATE {$tblBt} SET {$colBt} = ? WHERE {$pkBt} IN ({$inList})");
            $stmt->execute($params);
            $affected = $stmt->rowCount();
        } catch (\PDOException $e) {
            http_response_code(500);
            exit(json_encode(['error' => 'MySQL update failed.']));
        }
        $uid = (int)$_SESSION['user_id'];
        log_user_action($conn, $uid, 'MASS_EDIT', $tableName, null);
        exit(json_encode(['updated' => $affected]));
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

    if (in_array($tableName, $mysqlGatewayTables, true)) {
        http_response_code(422);
        exit(json_encode(['error' => 'Mass duplicate is not supported for external MySQL tables']));
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

    if (!empty($tableCfg['owner_restricted'])) {
        $uid      = (int)$_SESSION['user_id'];
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

    // Register ownership for every new row so owner-restricted tables stay protected.
    if (!empty($tableCfg['owner_restricted'])) {
        foreach ($newIds as $newId) {
            set_record_owner($conn, $tableName, $newId, $uid, $uid);
        }
    }

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

    if (in_array($tableName, $mysqlGatewayTables, true)) {
        $pdo = mysql_pdo('mass_edit');
        if ($pdo === null) {
            http_response_code(503);
            exit(json_encode(['error' => 'MySQL connection unavailable']));
        }
        $mysqlPk = (string)($tableCfg['mysql_pk'] ?? 'id');
        $pkBt    = mysql_bt($mysqlPk);
        $tblBt   = mysql_bt(MYSQL_DB) . '.' . mysql_bt($tableName);
        $inList  = implode(',', array_fill(0, count($rowIds), '?'));
        try {
            $stmt = $pdo->prepare("DELETE FROM {$tblBt} WHERE {$pkBt} IN ({$inList})");
            $stmt->execute($rowIds);
            $affected = $stmt->rowCount();
        } catch (\PDOException $e) {
            http_response_code(500);
            exit(json_encode(['error' => 'MySQL delete failed.']));
        }
        $uid = (int)$_SESSION['user_id'];
        log_user_action($conn, $uid, 'MASS_DELETE', $tableName, null);
        exit(json_encode(['deleted' => $affected]));
    }

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
