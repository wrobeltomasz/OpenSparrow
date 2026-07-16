<?php

declare(strict_types=1);

// api/owners.php — Record ownership API (current + historical owner per record)
// Auth gate: session + UA enforcement; CSRF on POST; write actions go through requireWrite()
// match() action routing: get, history, editors, set, mass_set — keyed by (table_name, record_id), is_current flag
// sys_table('record_owners'); parameterized queries; JSON via jsonError()/jsonSuccess()

require_once __DIR__ . '/../../includes/bootstrap.php';

// csrf=manual: mutating actions validate the body token via os_require_csrf() themselves
$conn = os_api_bootstrap(['csrf' => 'manual']);

// jsonError(), jsonSuccess(), requireLogin(), requireWrite() and validatedTable()
// are shared via includes/api_helpers.php

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
        'mine'     => actionMine($conn),
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

// "My records" — cross-table list of records currently owned by the logged-in user,
// grouped by table with a best-effort display label per record. Label columns and the
// per-table record limit come from the "user_records" config (admin "User Records" tab).
function actionMine($conn): void
{
    requireLogin();

    $userId = (int)($_SESSION['user_id'] ?? 0);

    // Most-recently-assigned first, per table, so the per-table limit below keeps the
    // freshest assignments.
    $sql = "
        SELECT table_name, record_id
        FROM " . sys_table('record_owners') . "
        WHERE owner_id = \$1 AND is_current = true
        ORDER BY table_name, changed_at DESC, record_id DESC
    ";

    $res = pg_query_params($conn, $sql, [$userId]);
    if (!$res) {
        error_log('[api_owners actionMine] ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    require_once __DIR__ . '/../../includes/config_store.php';
    $userRecordsCfg  = config_get('user_records') ?? [];
    $configuredCols  = is_array($userRecordsCfg['columns'] ?? null) ? $userRecordsCfg['columns'] : [];
    $limit           = (int)($userRecordsCfg['limit'] ?? 20);

    $byTable = [];
    while ($row = pg_fetch_assoc($res)) {
        $tableName = $row['table_name'];
        if ($limit > 0 && count($byTable[$tableName] ?? []) >= $limit) {
            continue;
        }
        $byTable[$tableName][] = (int)$row['record_id'];
    }

    $schema = config_get('schema') ?? [];

    $tables = [];
    foreach ($byTable as $tableName => $ids) {
        $tableCfg = $schema['tables'][$tableName] ?? null;
        // Ownership rows can outlive their table (renamed/removed from schema.json).
        if ($tableCfg === null || !empty($tableCfg['hidden'])) {
            continue;
        }

        $labelCols = mine_label_columns($tableCfg, $configuredCols[$tableName] ?? []);
        $pgSchema  = $tableCfg['schema'] ?? 'public';
        $arrParam  = '{' . implode(',', $ids) . '}';

        $escapedLabelCols = array_map('pg_ident', $labelCols);
        $labelSql = count($escapedLabelCols) > 1
            ? "CONCAT_WS(' - ', " . implode(', ', $escapedLabelCols) . ')'
            : $escapedLabelCols[0];

        $rowsSql = sprintf(
            'SELECT id, %s AS label FROM %s.%s WHERE id = ANY($1::int[])',
            $labelSql,
            pg_ident($pgSchema),
            pg_ident($tableName)
        );

        $rowsRes = pg_query_params($conn, $rowsSql, [$arrParam]);
        if (!$rowsRes) {
            error_log('[api_owners actionMine] ' . pg_last_error($conn));
            continue;
        }

        $records = [];
        while ($r = pg_fetch_assoc($rowsRes)) {
            $label = trim((string)($r['label'] ?? ''));
            $records[] = [
                'id'    => (int)$r['id'],
                'label' => $label !== '' ? $label : ('#' . $r['id']),
            ];
        }
        usort($records, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

        $tables[] = [
            'table'        => $tableName,
            'display_name' => to_display_name($tableCfg),
            'records'      => $records,
        ];
    }

    usort($tables, fn($a, $b) => strnatcasecmp($a['display_name'], $b['display_name']));

    jsonSuccess(['tables' => $tables]);
}

// Record label column(s), concatenated with CONCAT_WS() when there's more than one.
// Prefers the admin-configured "user_records" columns for this table (set via
// the admin "User Records" > "Column Mapping" tab); falls back to a best-effort guess
// (first text column shown in the grid, else any grid column, else the id).
function mine_label_columns(array $tableCfg, array $configured): array
{
    $cols = $tableCfg['columns'] ?? [];

    if (!empty($configured)) {
        $valid = array_values(array_filter(
            $configured,
            fn($c) => is_string($c) && isset($cols[$c]) && ($cols[$c]['type'] ?? '') !== 'virtual'
        ));
        if (!empty($valid)) {
            return $valid;
        }
    }

    $firstGridCol = null;
    foreach ($cols as $colName => $colCfg) {
        if (empty($colCfg['show_in_grid'])) {
            continue;
        }
        if ($firstGridCol === null) {
            $firstGridCol = $colName;
        }
        if (($colCfg['type'] ?? '') === 'text') {
            return [$colName];
        }
    }
    return [$firstGridCol ?? 'id'];
}

function actionMassSet($conn, array $body): void
{
    requireWrite();
    os_require_csrf('body', $body);

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
