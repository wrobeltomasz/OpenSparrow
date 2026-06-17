<?php

declare(strict_types=1);

// api/data_cleanup.php — Bulk data-cleanup API (editor-only)
// Auth gate: session + UA enforcement; requires editor role; CSRF on POST
// POST actions: data_cleanup_preview (dry-run report), data_cleanup_apply (executes removal of orphaned/stale records based on schema relations)
// Reads config/schema.json + config/mysql_gateway.json; parameterized queries; sys_table()

ini_set('display_errors', '0');
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

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

$schemaJson = file_get_contents(__DIR__ . '/../../config/schema.json');
$schema     = json_decode($schemaJson, true, 512, JSON_THROW_ON_ERROR);

$mysqlGatewayPath   = __DIR__ . '/../../config/mysql_gateway.json';
$mysqlGatewayTables = [];
if (file_exists($mysqlGatewayPath)) {
    $mgCfg              = json_decode(file_get_contents($mysqlGatewayPath), true);
    $mysqlGatewayTables = is_array($mgCfg) ? ($mgCfg['mysql_tables'] ?? []) : [];
}

// Escape a string for use as a POSIX ERE literal (all metacharacters neutralised).
function pgRegexEscape(string $s): string
{
    $special = ['.', '*', '+', '?', '[', ']', '{', '}', '(', ')', '|', '^', '$', '\\'];
    $result  = '';
    $len     = mb_strlen($s, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch      = mb_substr($s, $i, 1, 'UTF-8');
        $result .= in_array($ch, $special, true) ? '\\' . $ch : $ch;
    }
    return $result;
}

// Build a POSIX ERE character class for each char that covers its accented variants
// (both lower and upper case). Non-accented chars are regex-escaped literally.
function buildAccentPattern(string $text): string
{
    // Base → set of all variants (lowercase keys only; uppercase auto-derived).
    $map = [
        'a' => 'aàáâãäåą',
        'c' => 'cćçč',
        'd' => 'dď',
        'e' => 'eèéêëę',
        'g' => 'gğ',
        'i' => 'iìíîï',
        'l' => 'lłľ',
        'n' => 'nñňń',
        'o' => 'oòóôõöøő',
        'r' => 'rř',
        's' => 'sśşšß',
        't' => 'tťþ',
        'u' => 'uùúûüů',
        'y' => 'yý',
        'z' => 'zźżž',
    ];

    $result = '';
    $lower  = mb_strtolower($text, 'UTF-8');
    $len    = mb_strlen($lower, 'UTF-8');

    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($lower, $i, 1, 'UTF-8');
        if (isset($map[$ch])) {
            $lowerVariants = preg_split('//u', $map[$ch], -1, PREG_SPLIT_NO_EMPTY);
            $upperVariants = array_map(fn($c) => mb_strtoupper($c, 'UTF-8'), $lowerVariants);
            $all = array_unique(array_merge($lowerVariants, $upperVariants));
            // Escape chars that have special meaning inside a POSIX character class.
            $escaped = implode('', array_map(function ($c) {
                return in_array($c, [']', '\\', '^', '-'], true) ? '\\' . $c : $c;
            }, $all));
            $result .= '[' . $escaped . ']';
        } else {
            $result .= pgRegexEscape($ch);
        }
    }
    return $result;
}

// Validate table + column from request body against schema.json.
// Returns [tableCfg, schemaName, colSql, tblSql] or exits with error.
function validateInput(array $body, array $schema, $conn): array
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
    if (!isset($cols[$colName]) || ($cols[$colName]['type'] ?? '') === 'virtual') {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid column']));
    }

    $schemaName = $tableCfg['schema'] ?? 'public';
    $tblSql     = pg_ident($schemaName) . '.' . pg_ident($tableName);
    $colSql     = pg_ident($colName);

    return [$tableCfg, $schemaName, $tableName, $colSql, $tblSql];
}

// Build regex pattern and SQL expression components from request flags.
function buildExpressions(
    string $find,
    string $replace,
    bool $caseInsensitive,
    bool $wholeWord,
    bool $ignoreAccents
): array {
    $pattern = $ignoreAccents ? buildAccentPattern($find) : pgRegexEscape($find);

    if ($wholeWord) {
        $pattern = '\\y' . $pattern . '\\y';
    }

    $flags   = $caseInsensitive ? 'ig' : 'g';
    $whereOp = $caseInsensitive ? '~*' : '~';

    // Escape backslashes in replacement to prevent regexp_replace backreference substitution.
    $safeReplace = str_replace('\\', '\\\\', $replace);

    return [$pattern, $flags, $whereOp, $safeReplace];
}

if ($action === 'data_cleanup_preview' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $find      = (string)($body['find']    ?? '');
    $replace   = (string)($body['replace'] ?? '');
    $caseInsensitive = !empty($body['case_insensitive']);
    $wholeWord       = !empty($body['whole_word']);
    $ignoreAccents   = !empty($body['ignore_accents']);

    if ($find === '') {
        exit(json_encode(['count' => 0, 'rows' => []]));
    }

    [$tableCfg, , $tableName, $colSql, $tblSql] = validateInput($body, $schema, $conn);

    if (in_array($tableName, $mysqlGatewayTables, true)) {
        http_response_code(422);
        exit(json_encode(['error' => 'Data Cleanup is not supported for external MySQL tables']));
    }

    [$pattern, $flags, $whereOp, $safeReplace] = buildExpressions(
        $find,
        $replace,
        $caseInsensitive,
        $wholeWord,
        $ignoreAccents
    );

    $whereSql   = "{$colSql} {$whereOp} \$1 AND {$colSql} IS NOT NULL";
    $replaceExp = "regexp_replace({$colSql}, \$1, \$2, '{$flags}')";

    // Owner-restricted preview: count and sample rows must match what apply will actually touch.
    // Param positions differ between count ($1=pattern) and row ($1=pattern,$2=safeReplace),
    // so the owner params land at $2/$3 vs $3/$4 respectively — separate branches required.
    if (!empty($tableCfg['owner_restricted'])) {
        $tOwners  = sys_table('record_owners');
        $uid      = (int)$_SESSION['user_id'];
        $ownerCnt = " AND NOT EXISTS (SELECT 1 FROM {$tOwners} ro WHERE ro.table_name = \$2 AND ro.record_id = id AND ro.is_current = true AND ro.owner_id != \$3)";
        $ownerRow = " AND NOT EXISTS (SELECT 1 FROM {$tOwners} ro WHERE ro.table_name = \$3 AND ro.record_id = id AND ro.is_current = true AND ro.owner_id != \$4)";

        $cntRes = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$tblSql} WHERE {$whereSql}{$ownerCnt}",
            [$pattern, $tableName, $uid]
        );
        if (!$cntRes) {
            http_response_code(500);
            exit(json_encode(['error' => 'Database query failed.']));
        }
        $count = (int)pg_fetch_result($cntRes, 0, 0);
        pg_free_result($cntRes);

        $rowRes = @pg_query_params(
            $conn,
            "SELECT id, {$colSql} AS before_val, {$replaceExp} AS after_val
             FROM {$tblSql}
             WHERE {$whereSql}{$ownerRow}
             LIMIT 20",
            [$pattern, $safeReplace, $tableName, $uid]
        );
    } else {
        $cntRes = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$tblSql} WHERE {$whereSql}",
            [$pattern]
        );
        if (!$cntRes) {
            http_response_code(500);
            exit(json_encode(['error' => 'Database query failed.']));
        }
        $count = (int)pg_fetch_result($cntRes, 0, 0);
        pg_free_result($cntRes);

        $rowRes = @pg_query_params(
            $conn,
            "SELECT id, {$colSql} AS before_val, {$replaceExp} AS after_val
             FROM {$tblSql}
             WHERE {$whereSql}
             LIMIT 20",
            [$pattern, $safeReplace]
        );
    }

    if (!$rowRes) {
        http_response_code(500);
        exit(json_encode(['error' => 'Database query failed.']));
    }

    $rows = [];
    while ($row = pg_fetch_assoc($rowRes)) {
        $rows[] = ['id' => $row['id'], 'before' => $row['before_val'], 'after' => $row['after_val']];
    }
    pg_free_result($rowRes);

    exit(json_encode(['count' => $count, 'rows' => $rows]));
}

if ($action === 'data_cleanup_apply' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $find      = (string)($body['find']    ?? '');
    $replace   = (string)($body['replace'] ?? '');
    $caseInsensitive = !empty($body['case_insensitive']);
    $wholeWord       = !empty($body['whole_word']);
    $ignoreAccents   = !empty($body['ignore_accents']);

    if ($find === '') {
        http_response_code(400);
        exit(json_encode(['error' => 'Find string required']));
    }

    [$tableCfg, , $tableName, $colSql, $tblSql] = validateInput($body, $schema, $conn);

    if (in_array($tableName, $mysqlGatewayTables, true)) {
        http_response_code(422);
        exit(json_encode(['error' => 'Data Cleanup is not supported for external MySQL tables']));
    }

    [$pattern, $flags, $whereOp, $safeReplace] = buildExpressions(
        $find,
        $replace,
        $caseInsensitive,
        $wholeWord,
        $ignoreAccents
    );

    $whereSql   = "{$colSql} {$whereOp} \$1 AND {$colSql} IS NOT NULL";
    $replaceExp = "regexp_replace({$colSql}, \$1, \$2, '{$flags}')";

    @pg_query($conn, 'BEGIN');

    if (!empty($tableCfg['owner_restricted'])) {
        $tOwners  = sys_table('record_owners');
        $uid      = (int)$_SESSION['user_id'];
        $ownerSql = " AND NOT EXISTS (SELECT 1 FROM {$tOwners} ro WHERE ro.table_name = \$3 AND ro.record_id = _t.id AND ro.is_current = true AND ro.owner_id != \$4)";
        $res = @pg_query_params(
            $conn,
            "UPDATE {$tblSql} AS _t SET {$colSql} = {$replaceExp} WHERE {$whereSql}{$ownerSql}",
            [$pattern, $safeReplace, $tableName, $uid]
        );
    } else {
        $res = @pg_query_params(
            $conn,
            "UPDATE {$tblSql} SET {$colSql} = {$replaceExp} WHERE {$whereSql}",
            [$pattern, $safeReplace]
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
    log_user_action($conn, $uid, 'DATA_CLEANUP', $tableName, null);

    exit(json_encode(['updated' => $affected]));
}

http_response_code(400);
exit(json_encode(['error' => 'Unknown action']));
