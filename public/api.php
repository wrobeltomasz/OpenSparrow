<?php

declare(strict_types=1);

// api.php — Main CRUD/data REST API for the frontend (core data endpoint, largest file)
// Auth gate: session + hard lifetime/UA enforcement + CSRF for POST/PATCH/DELETE; admin blocked, viewer read-only
// Routes by HTTP method against the "schema" config tables; also self-service profile actions (update_avatar, change_password), i18n_bundle, calendar/board move, mass insert
// Records stored in PostgreSQL; every write does log_user_action() audit, snapshot_record(), and automations (automations.php)
// All identifiers via pg_ident(), values parameterized; uses sys_table() for system tables

use App\Security\UserRole;

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/config_store.php';

// Auth gate, staleness enforcement and header-CSRF for POST/PATCH/DELETE.
// connect=false: the DB connection is opened per-branch below.
os_api_bootstrap(['connect' => false]);

$method = $_SERVER['REQUEST_METHOD'];
$role = UserRole::fromSession();

// Self-service profile actions — permitted for every authenticated user regardless of role
$profileAction = $_GET['action'] ?? '';
if (in_array($profileAction, ['update_avatar', 'change_password'], true)) {
    $conn = db_connect();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $uid  = (int)$_SESSION['user_id'];
// POST: save chosen avatar (1-24) or clear it (null)
    if ($profileAction === 'update_avatar' && $method === 'POST') {
        $avatarId = array_key_exists('avatar_id', $body) ? $body['avatar_id'] : false;
        if ($avatarId === false) {
            http_response_code(400);
            exit(json_encode(['error' => 'avatar_id required']));
        }
        if ($avatarId !== null && (!is_int($avatarId) || $avatarId < 1 || $avatarId > 24)) {
            http_response_code(400);
            exit(json_encode(['error' => 'avatar_id must be 1-24 or null']));
        }

        $sql = 'UPDATE ' . sys_table('users') . ' SET avatar_id = $1 WHERE id = $2';
        $res = @pg_query_params($conn, $sql, [$avatarId, $uid]);
        if (!$res) {
            http_response_code(500);
            exit(json_encode(['error' => 'Database error']));
        }

        $_SESSION['avatar_id'] = $avatarId;
        exit(json_encode(['ok' => true]));
    }

    // POST: change own password — verify current, enforce minimum length, rehash
    if ($profileAction === 'change_password' && $method === 'POST') {
        $current = $body['current_password'] ?? '';
        $new     = $body['new_password'] ?? '';
        if ($current === '' || $new === '') {
            http_response_code(400);
            exit(json_encode(['error' => 'Both passwords are required.']));
        }
        if (strlen($new) < 8) {
            http_response_code(422);
            exit(json_encode(['error' => 'New password must be at least 8 characters.']));
        }

        $sqlFetch = 'SELECT password_hash, salt FROM ' . sys_table('users') . ' WHERE id = $1';
        $resFetch = @pg_query_params($conn, $sqlFetch, [$uid]);
        if (!$resFetch) {
            http_response_code(500);
            exit(json_encode(['error' => 'Database error']));
        }

        $row      = pg_fetch_assoc($resFetch);
        $salt     = $row['salt'] ?? '';
        $toVerify = $salt !== '' ? $salt . $current : $current;
        if (!password_verify($toVerify, $row['password_hash'])) {
            http_response_code(422);
            exit(json_encode(['error' => 'Current password is incorrect.']));
        }

        $newSalt    = bin2hex(random_bytes(32));
        $newHash    = password_hash($newSalt . $new, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
        $sqlUpd = 'UPDATE ' . sys_table('users')
            . ' SET password_hash = $1, salt = $2, password_algo = $3, password_params = $4 WHERE id = $5';
        $params = [
            $newHash,
            $newSalt,
            'argon2id',
            json_encode(ARGON2_OPTIONS),
            $uid,
        ];
        $resUpd = @pg_query_params($conn, $sqlUpd, $params);
        if (!$resUpd) {
            http_response_code(500);
            exit(json_encode(['error' => 'Database error']));
        }

        log_user_action($conn, $uid, 'CHANGE_PASSWORD');
        exit(json_encode(['ok' => true]));
    }

    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// Translation bundle — all authenticated users, no DB required
if ($profileAction === 'i18n_bundle' && $method === 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    echo json_encode(I18n::flatBundle(), JSON_UNESCAPED_UNICODE);
    exit;
}

// Admin role is restricted to the admin panel; block from frontend data API
if ($role === UserRole::Admin) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden: Admin accounts cannot access the frontend data API.']));
}

// Block data modification requests for viewer users
if ($role === UserRole::Viewer && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden: Read-only access']));
}

// Load schema from the spw_config store
$schema = config_get('schema');
if ($schema === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot read schema configuration']);
    exit;
}
$schemaJson = json_encode($schema);
// Connect to DB (db.php + api_helpers.php are already loaded by the bootstrap)
$conn = db_connect();
require_once __DIR__ . '/../includes/automations.php';

try {
// GET: SCHEMA DATA
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'schema') {
        echo $schemaJson;
        exit;
    }

    // GET: WORKFLOWS DATA
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'workflows') {
        $workflows = config_get('workflows');
        if ($workflows === null) {
            echo json_encode(['menu_name' => 'Workflows', 'workflows' => []]);
            exit;
        }

        echo json_encode($workflows);
        exit;
    }

    // GET: DASHBOARD DATA
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'dashboard') {
        require_once __DIR__ . '/../includes/dashboard_query.php';
        $dashboard = config_get('dashboard');
        if ($dashboard === null) {
            echo json_encode(['layout' => [], 'widgets' => []]);
            exit;
        }
// Include menu config so frontend can build the sidebar
        $response = [
            'menu_name' => $dashboard['menu_name'] ?? 'Dashboard',
            'menu_icon' => $dashboard['menu_icon'] ?? '',
            'hidden' => !empty($dashboard['hidden']),
            'layout' => $dashboard['layout'] ?? [],
            'widgets' => []
        ];
        foreach ($dashboard['widgets'] ?? [] as $widget) {
            $table = $widget['table'] ?? '';
            if (!$table) {
                continue;
            }

            try {
                $tableCfg = safe_table($schema, $table);
            } catch (Throwable $e) {
                continue;
            }

            $schemaName = $tableCfg['schema'] ?? 'public';
            $qType = $widget['query']['type'] ?? 'list';
            $data = null;
            $sqlWhere = '';
// Build WHERE from structured conditions (column validated against schema, values escaped)
            $conditions = is_array($widget['query']['conditions'] ?? null) ? $widget['query']['conditions'] : [];
            // Parenthesised so the appended date-range AND below cannot rebind a
            // widget-level OR (AND binds tighter than OR in SQL).
            $condSql = dashboard_conditions_sql($conn, $tableCfg, $conditions);

            // Apply Global Date Filter if requested and target matches.
            // $dateSqlPrev covers the equally long window directly before the
            // current one and powers the previous-period delta on stat cards.
            $dateFilter = $_GET['date_filter'] ?? 'all';
            $dateTarget = $_GET['date_target'] ?? 'all';
            $widgetTargetId = $widget['id'] ?? $widget['table'] ?? '';
            $dateSqlCur  = null;
            $dateSqlPrev = null;
            if ($dateFilter !== 'all' && ($dateTarget === 'all' || $dateTarget === $widgetTargetId)) {
                // First column that represents a date/time ('time' also matches 'timestamp')
                $dateCol = array_find_key($tableCfg['columns'], static function (array $cCfg): bool {
                    $cType = strtolower($cCfg['type'] ?? '');
                    return str_contains($cType, 'date') || str_contains($cType, 'time');
                });

                if ($dateCol) {
                    $dc = pg_ident($dateCol);
                    [$dateSqlCur, $dateSqlPrev] = match ($dateFilter) {
                        'today' => [
                            $dc . ' >= CURRENT_DATE',
                            '(' . $dc . " >= CURRENT_DATE - INTERVAL '1 day' AND " . $dc . ' < CURRENT_DATE)',
                        ],
                        '7d' => [
                            $dc . " >= CURRENT_DATE - INTERVAL '7 days'",
                            '(' . $dc . " >= CURRENT_DATE - INTERVAL '14 days' AND " . $dc . " < CURRENT_DATE - INTERVAL '7 days')",
                        ],
                        '30d' => [
                            $dc . " >= CURRENT_DATE - INTERVAL '30 days'",
                            '(' . $dc . " >= CURRENT_DATE - INTERVAL '60 days' AND " . $dc . " < CURRENT_DATE - INTERVAL '30 days')",
                        ],
                        'this_month' => [
                            "DATE_TRUNC('month', " . $dc . ") = DATE_TRUNC('month', CURRENT_DATE)",
                            "DATE_TRUNC('month', " . $dc . ") = DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '1 month'",
                        ],
                        default => [null, null],
                    };
                }
            }

            $whereParts = array_values(array_filter([$condSql, $dateSqlCur ?? '']));
            $sqlWhere = empty($whereParts) ? '' : ' WHERE ' . implode(' AND ', $whereParts);
            $sqlWherePrev = null;
            if ($dateSqlPrev !== null) {
                $prevParts = array_values(array_filter([$condSql, $dateSqlPrev]));
                $sqlWherePrev = ' WHERE ' . implode(' AND ', $prevParts);
            }

            $result = dashboard_run_widget_query($conn, $tableCfg, $schemaName, $table, $widget['query'] ?? [], $widget['display_columns'] ?? [id_column()], $sqlWhere);
            $data = $result['data'];
            if (isset($result['sql_error'])) {
                $widget['sql_error'] = $result['sql_error'];
            }
            if (isset($result['column_type'])) {
                $widget['column_type'] = $result['column_type'];
            }
            if (isset($result['column_types'])) {
                $widget['column_types'] = $result['column_types'];
            }

            // Previous-period comparison only applies to single-value widgets
            if ($sqlWherePrev !== null && in_array($qType, ['count', 'sum', 'avg'], true) && !isset($result['sql_error'])) {
                $prevResult = dashboard_run_widget_query($conn, $tableCfg, $schemaName, $table, $widget['query'] ?? [], $widget['display_columns'] ?? [id_column()], $sqlWherePrev);
                if (!isset($prevResult['sql_error'])) {
                    $widget['prev_data'] = $prevResult['data'];
                }
            }

            $widget['data'] = $data;
            $response['widgets'][] = $widget;
        }

        echo json_encode($response);
        exit;
    }

    // GET: CALENDAR DATA
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'calendar') {
        $calendar = config_get('calendar');
        if ($calendar === null) {
            echo json_encode(['events' => []]);
            exit;
        }

        // Accept optional year/month params so the frontend can request only the
        // visible month. Fall back to the current month when omitted.
        $reqYear  = filter_var($_GET['year']  ?? date('Y'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999]]);
        $reqMonth = filter_var($_GET['month'] ?? date('n'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
        if ($reqYear  === false) {
            $reqYear  = (int)date('Y');
        }
        if ($reqMonth === false) {
            $reqMonth = (int)date('n');
        }
        $dateFrom = sprintf('%04d-%02d-01', $reqYear, $reqMonth);
        $dateTo   = date('Y-m-t', mktime(0, 0, 0, $reqMonth, 1, $reqYear));

        $events = [];
        foreach ($calendar['sources'] ?? [] as $src) {
            $table = $src['table'] ?? '';
            if (!$table) {
                continue;
            }

            try {
                $tableCfg = safe_table($schema, $table);
            } catch (Throwable $e) {
                continue;
            }

            $schemaName = $tableCfg['schema'] ?? 'public';
            $idCol = id_column();
            $titleCol = $src['title_column'] ?? $idCol;
            $dateCol = $src['date_column'] ?? '';
            $color = $src['color'] ?? '#3b82f6';
            if (isset($tableCfg['columns'][$dateCol])) {
                $cols = column_list($tableCfg);
                $selectCols = array_values(array_unique(array_merge([$idCol], $cols)));

                $selectSql = implode(', ', array_map(fn($c) => pg_ident($c), $selectCols));
                $sql = sprintf(
                    'SELECT %s FROM %s.%s WHERE %s IS NOT NULL AND %s BETWEEN $1 AND $2',
                    $selectSql,
                    pg_ident($schemaName),
                    pg_ident($table),
                    pg_ident($dateCol),
                    pg_ident($dateCol)
                );
                $res = @pg_query_params($conn, $sql, [$dateFrom, $dateTo]);
                if ($res) {
                    $rows = [];
                    while ($r = pg_fetch_assoc($res)) {
                        $rows[] = $r;
                    }
                    pg_free_result($res);
                    $rows = map_fk_display($schema, $tableCfg, $rows);
                    foreach ($rows as $r) {
                        $events[] = [
                            'id' => $r[$idCol],
                            'table' => $table,
                            'title' => $r[$titleCol] ?? 'No title',
                            'date' => substr($r[$dateCol], 0, 10),
                            'color' => $color,
                            'icon' => $src['icon'] ?? null,
                            'rowData' => $r
                        ];
                    }
                }
            }
        }

        echo json_encode([
            'menu_name' => $calendar['menu_name'] ?? 'Calendar',
            'menu_icon' => $calendar['menu_icon'] ?? '',
            'hidden' => !empty($calendar['hidden']),
            'events' => $events
        ]);
        exit;
    }

    // GET: BOARD (KANBAN) DATA
    // Returns the board configuration plus its lanes (one per status value) and
    // the records of the configured table grouped client-side by their status.
    // Boards are a named list (config key "board" -> "boards": [...]); ?board=
    // selects which one, falling back to the first configured board.
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'board') {
        $boardsCfg = config_get('board') ?? [];
        $boardId   = substr($_GET['board'] ?? '', 0, 64);
        $boardCfg  = null;
        foreach (($boardsCfg['boards'] ?? []) as $b) {
            if (($b['id'] ?? '') === $boardId) {
                $boardCfg = $b;
                break;
            }
        }
        if ($boardCfg === null) {
            $boardCfg = $boardsCfg['boards'][0] ?? [];
        }

        $meta = [
            'menu_name'     => $boardCfg['menu_name'] ?? 'Board',
            'menu_icon'     => $boardCfg['menu_icon'] ?? '',
            'hidden'        => !empty($boardCfg['hidden']),
            'configured'    => false,
            'table'         => $boardCfg['table'] ?? '',
            'status_column' => $boardCfg['status_column'] ?? '',
            'columns'       => [],
            'cards'         => [],
            'can_edit'      => $role !== UserRole::Viewer,
        ];

        $table     = $boardCfg['table'] ?? '';
        $statusCol = $boardCfg['status_column'] ?? '';
        if ($table === '' || $statusCol === '') {
            echo json_encode($meta);
            exit;
        }

        try {
            $tableCfg = safe_table($schema, $table);
        } catch (Throwable $e) {
            echo json_encode($meta);
            exit;
        }

        if (!isset($tableCfg['columns'][$statusCol])) {
            echo json_encode($meta);
            exit;
        }

        $schemaName   = $tableCfg['schema'] ?? 'public';
        $idCol        = id_column();
        $titleCol     = $boardCfg['title_column'] ?? '';
        if ($titleCol === '' || !isset($tableCfg['columns'][$titleCol])) {
            $titleCol = $idCol;
        }
        $defaultColor = $boardCfg['color'] ?? '#005A9E';

        // Card detail rows: only configured columns that still exist on the table.
        $cardCols = [];
        foreach (($boardCfg['card_columns'] ?? []) as $c) {
            if (is_string($c) && isset($tableCfg['columns'][$c]) && $c !== $statusCol) {
                $cardCols[] = $c;
            }
        }

        // Lanes: an enum status column defines lanes (with colors) from its
        // declared options; any other column derives lanes from the distinct
        // values present in the data.
        $statusDef  = $tableCfg['columns'][$statusCol];
        $statusType = strtolower($statusDef['type'] ?? '');
        $enumColors = is_array($statusDef['enum_colors'] ?? null) ? $statusDef['enum_colors'] : [];
        $lanes      = [];
        if ($statusType === 'enum' && is_array($statusDef['options'] ?? null)) {
            foreach ($statusDef['options'] as $opt) {
                $val = (string)$opt;
                $lanes[] = [
                    'value' => $val,
                    'label' => $val,
                    'color' => $enumColors[$val] ?? $defaultColor,
                ];
            }
        } else {
            $sqlDistinct = sprintf(
                'SELECT DISTINCT %s AS v FROM %s.%s WHERE %s IS NOT NULL ORDER BY 1',
                pg_ident($statusCol),
                pg_ident($schemaName),
                pg_ident($table),
                pg_ident($statusCol)
            );
            $rd = @pg_query($conn, $sqlDistinct);
            if ($rd) {
                while ($r = pg_fetch_assoc($rd)) {
                    $val = (string)$r['v'];
                    $lanes[] = ['value' => $val, 'label' => $val, 'color' => $defaultColor];
                }
                pg_free_result($rd);
            }
        }

        // Records — newest first; FK columns resolved to their display labels.
        $cols       = column_list($tableCfg);
        $selectCols = array_values(array_unique(array_merge([$idCol, $statusCol, $titleCol], $cols)));
        $cards = [];
        $selectSql  = implode(', ', array_map(fn($c) => pg_ident($c), $selectCols));
        $sql = sprintf(
            'SELECT %s FROM %s.%s ORDER BY %s DESC',
            $selectSql,
            pg_ident($schemaName),
            pg_ident($table),
            pg_ident($idCol)
        );
        $res  = @pg_query($conn, $sql);
        $rows = [];
        if ($res) {
            while ($r = pg_fetch_assoc($res)) {
                $rows[] = $r;
            }
            pg_free_result($res);
        }
        $rows = map_fk_display($schema, $tableCfg, $rows);
        foreach ($rows as $r) {
            $fields = [];
            foreach ($cardCols as $c) {
                $label = $tableCfg['columns'][$c]['display_name'] ?? $c;
                $value = $r[$c . '__display'] ?? $r[$c] ?? '';
                if ($value === null || $value === '') {
                    continue;
                }
                $fields[] = ['label' => $label, 'value' => $value];
            }
            $cards[] = [
                'id'      => $r[$idCol],
                'status'  => (string)($r[$statusCol] ?? ''),
                'title'   => $r[$titleCol . '__display'] ?? $r[$titleCol] ?? ('#' . $r[$idCol]),
                'fields'  => $fields,
                'rowData' => $r,
            ];
        }

        $meta['configured']    = true;
        $meta['title_column']  = $titleCol;
        $meta['default_color'] = $defaultColor;
        $meta['status_label']  = $statusDef['display_name'] ?? $statusCol;
        $meta['table_label']   = $tableCfg['display_name'] ?? $table;
        $meta['columns']       = $lanes;
        $meta['cards']         = $cards;
        echo json_encode($meta);
        exit;
    }

    // GET: BATCH M2M RELATED LABELS FOR GRID COLUMN
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'm2m_rows') {
        $table   = $_GET['table']     ?? '';
        $m2mIdx  = (int)($_GET['m2m_index'] ?? 0);
        $idsRaw  = $_GET['ids']       ?? '';
        if (!isset($schema['tables'][$table])) {
            exit(json_encode(['data' => (object)[]]));
        }

        $ids = array_values(array_filter(explode(',', $idsRaw), 'ctype_digit'));
        if (empty($ids)) {
            exit(json_encode(['data' => (object)[]]));
        }

        $m2mList = $schema['tables'][$table]['many_to_many'] ?? [];
        if (!isset($m2mList[$m2mIdx])) {
            exit(json_encode(['data' => (object)[]]));
        }

        $cfg        = $m2mList[$m2mIdx];
        $jt         = $cfg['junction_table'] ?? '';
        $selfFk     = $cfg['self_fk']        ?? '';
        $otherFk    = $cfg['other_fk']       ?? '';
        $otherTable = $cfg['other_table']    ?? '';
        $displayCol = $cfg['display_column'] ?? 'id';

        if (
            !$jt || !$selfFk || !$otherFk || !$otherTable
            || !isset($schema['tables'][$jt], $schema['tables'][$otherTable])
        ) {
            exit(json_encode(['data' => (object)[]]));
        }

        $jtSchema = $schema['tables'][$jt]['schema']         ?? 'public';
        $otSchema = $schema['tables'][$otherTable]['schema'] ?? 'public';
        $placeholders = implode(',', array_map(fn($i) => '$' . ($i + 1), array_keys($ids)));

        $sql = sprintf(
            'SELECT j.%s AS sid, o.%s AS label
               FROM %s.%s j
               JOIN %s.%s o ON o."id" = j.%s
              WHERE j.%s IN (%s)
              ORDER BY j.%s, o.%s',
            pg_ident($selfFk),
            pg_ident($displayCol),
            pg_ident($jtSchema),
            pg_ident($jt),
            pg_ident($otSchema),
            pg_ident($otherTable),
            pg_ident($otherFk),
            pg_ident($selfFk),
            $placeholders,
            pg_ident($selfFk),
            pg_ident($displayCol)
        );
        $res = @pg_query_params($conn, $sql, $ids);
        if (!$res) {
            exit(json_encode(['data' => (object)[]]));
        }

        $data = [];
        while ($row = pg_fetch_assoc($res)) {
            $sid = (string)$row['sid'];
            $data[$sid][] = (string)$row['label'];
        }

        exit(json_encode(['data' => $data ?: (object)[]]));
    }

    // GET: LIST TABLE ROWS
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'list') {
        $table = $_GET['table'] ?? '';
        $tableCfg = safe_table($schema, $table);
        $idCol = id_column();
        $schemaName = $tableCfg['schema'] ?? 'public';
        $cols = column_list($tableCfg);
        $selectCols = array_values(array_unique(array_merge([$idCol], $cols)));
        $selectSql = implode(', ', array_map(fn($c) => pg_ident($c), $selectCols));
        $filterCol  = $_GET['filter_col'] ?? '';
        $filterVal  = $_GET['filter_val'] ?? '';
        $filterFrom = $_GET['filter_from'] ?? '';
        $filterTo   = $_GET['filter_to'] ?? '';
        $whereSql = '';
        $params = [];
        if ($filterCol !== '' && ($filterVal !== '' || $filterFrom !== '' || $filterTo !== '')) {
            $allowedFilterCols = array_merge([$idCol], array_keys($tableCfg['columns'] ?? []));
            if (in_array($filterCol, $allowedFilterCols, true)) {
                if ($filterFrom !== '' || $filterTo !== '') {
                    // Half-open range filter [from, to) — used by time-series drill-down
                    // so a chart bucket maps to every row within that period.
                    $rangeClauses = [];
                    if ($filterFrom !== '') {
                        $rangeClauses[] = sprintf('%s >= $%d', pg_ident($filterCol), count($params) + 1);
                        $params[] = $filterFrom;
                    }
                    if ($filterTo !== '') {
                        $rangeClauses[] = sprintf('%s < $%d', pg_ident($filterCol), count($params) + 1);
                        $params[] = $filterTo;
                    }
                    $whereSql = ' WHERE ' . implode(' AND ', $rangeClauses);
                } else {
                    $whereSql = sprintf(' WHERE %s = $1', pg_ident($filterCol));
                    $params[] = $filterVal;
                }
            }
        }

        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $likeVal  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $paramNum = count($params) + 1;
            $searchClauses = array_map(
                fn($c) => sprintf('%s::text ILIKE $%d', pg_ident($c), $paramNum),
                $selectCols
            );
            $whereSql .= ($whereSql !== '' ? ' AND ' : ' WHERE ') . '(' . implode(' OR ', $searchClauses) . ')';
            $params[]  = $likeVal;
        }

        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $defaultSort  = $tableCfg['default_sort'] ?? [];
        $orderClauses = [];
        if (is_array($defaultSort)) {
            foreach ($defaultSort as $rule) {
                $col = $rule['column'] ?? '';
                $dir = strtoupper($rule['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
                if ($col !== '' && (isset($tableCfg['columns'][$col]) || $col === $idCol)) {
                    $orderClauses[] = pg_ident($col) . ' ' . $dir;
                }
            }
        }
        if (empty($orderClauses)) {
            $orderClauses[] = pg_ident($idCol) . ' DESC';
        }

        $initialLimit = (int)($tableCfg['initial_limit'] ?? 0);
        $rowCap       = $initialLimit > 0 ? $initialLimit : MAX_LIST_ROWS;

        $sql = sprintf(
            'SELECT %s, COUNT(1) OVER() AS __spw_total FROM %s.%s%s ORDER BY %s LIMIT %d OFFSET %d',
            $selectSql,
            pg_ident($schemaName),
            pg_ident($table),
            $whereSql,
            implode(', ', $orderClauses),
            $rowCap,
            $offset
        );
        $res = @pg_query_params($conn, $sql, $params);
        if (!$res) {
            error_log('[api][list] ' . pg_last_error($conn));
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
            exit;
        }

        $rows = [];
        $dbTotal = 0;
        while ($r = pg_fetch_assoc($res)) {
            if ($dbTotal === 0) {
                $dbTotal = (int)($r['__spw_total'] ?? 0);
            }
            unset($r['__spw_total']);
            $rows[] = $r;
        }
        pg_free_result($res);
        $rows = map_fk_display($schema, $tableCfg, $rows);
        $rowCount = count($rows);
        echo json_encode([
            'columns'   => $selectCols,
            'rows'      => $rows,
            'truncated' => $rowCount === $rowCap,
            'total'     => $dbTotal,
            'table'     => [
                'name'         => $table,
                'display_name' => to_display_name($tableCfg),
            ],
        ]);
        exit;
    }

    // GET: SUBTABLE COUNTS — total linked records per row across all configured subtables
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'subtable_counts') {
        $table     = $_GET['table'] ?? '';
        $tableCfg  = safe_table($schema, $table);
        $subtables = $tableCfg['subtables'] ?? [];

        if (empty($subtables)) {
            exit(json_encode(['success' => true, 'counts' => (object)[]]));
        }

        $rawIds = $_GET['ids'] ?? '';
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $rawIds)),
            fn($id) => $id > 0
        )));

        if (empty($ids)) {
            exit(json_encode(['success' => true, 'counts' => (object)[]]));
        }

        $idCol  = id_column();
        $counts = array_fill_keys(array_map('strval', $ids), 0);

        foreach ($subtables as $sub) {
            $subTable = $sub['table'] ?? '';
            $fkCol    = $sub['foreign_key'] ?? '';
            if ($subTable === '' || $fkCol === '') {
                continue;
            }
            if (!isset($schema['tables'][$subTable])) {
                continue;
            }
            $subCfg  = $schema['tables'][$subTable];
            $allowed = array_merge([$idCol], array_keys($subCfg['columns'] ?? []));
            if (!in_array($fkCol, $allowed, true)) {
                continue;
            }
            $subSchema    = $subCfg['schema'] ?? 'public';
            $placeholders = implode(',', array_map(fn($i) => '$' . ($i + 1), range(0, count($ids) - 1)));
            $sql = sprintf(
                'SELECT %s AS fk_val, COUNT(*) AS cnt FROM %s.%s WHERE %s IN (%s) GROUP BY %s',
                pg_ident($fkCol),
                pg_ident($subSchema),
                pg_ident($subTable),
                pg_ident($fkCol),
                $placeholders,
                pg_ident($fkCol)
            );
            $res = @pg_query_params($conn, $sql, $ids);
            if (!$res) {
                continue;
            }
            while ($r = pg_fetch_assoc($res)) {
                $key = (string)$r['fk_val'];
                if (isset($counts[$key])) {
                    $counts[$key] += (int)$r['cnt'];
                }
            }
            pg_free_result($res);
        }

        $nonZero = array_filter($counts, fn($v) => $v > 0);
        exit(json_encode(['success' => true, 'counts' => $nonZero ?: (object)[]]));
    }

    // POST / PATCH / DELETE
    if (in_array($method, ['POST','PATCH','DELETE'], true)) {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        $table = $body['table'] ?? '';
        $tableCfg = safe_table($schema, $table);
        $schemaName = $tableCfg['schema'] ?? 'public';
        $idCol = id_column();
// POST: CALENDAR MOVE EVENT (Drag & Drop functionality)
        if ($method === 'POST' && ($body['api'] ?? '') === 'calendar' && ($body['action'] ?? '') === 'move_event') {
            if ($role === UserRole::Viewer) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit;
            }

            // Load calendar configuration to validate source tables
            $calConfig = config_get('calendar') ?? ['sources' => []];
            $sources = $calConfig['sources'] ?? [];
// Whitelist payload table against configured calendar sources
            $allowedTables = array_column($sources, 'table');
            if (!in_array($table, $allowedTables, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid table']);
                exit;
            }

            $id = (int)($body['id'] ?? 0);
            $newDate = $body['newDate'] ?? '';
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid ID']);
                exit;
            }

            // Owner-restricted: prevent moving a record owned by someone else.
            check_record_ownership($conn, $tableCfg, $table, $id, (int)$_SESSION['user_id']);

            // Validate strict YYYY-MM-DD date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) || !checkdate((int)substr($newDate, 5, 2), (int)substr($newDate, 8, 2), (int)substr($newDate, 0, 4))) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid date format']);
                exit;
            }

            // Get date column for specific table configuration
            $dateColumn = '';
            foreach ($sources as $source) {
                if ($source['table'] === $table) {
                    $dateColumn = $source['date_column'];
                    break;
                }
            }

            if ($dateColumn === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Missing date column config']);
                exit;
            }

            // Perform safety regex check on column identifier
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $dateColumn)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid column name']);
                exit;
            }

            // Update record via native pg_query_params for robust SQL injection prevention
            $sql = sprintf('UPDATE %s.%s SET %s = $1 WHERE %s = $2', pg_ident($schemaName), pg_ident($table), pg_ident($dateColumn), pg_ident($idCol));
            $res = @pg_query_params($conn, $sql, [$newDate, $id]);
            if (!$res) {
                http_response_code(500);
                echo json_encode(['error' => 'Database error']);
                error_log('Calendar move_event error: ' . pg_last_error($conn));
                exit;
            }

            if (pg_affected_rows($res) === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Record not found']);
                exit;
            }

            log_user_action($conn, (int)$_SESSION['user_id'], 'CALENDAR_MOVE', $table, $id);

            echo json_encode(['success' => true]);
            exit;
        }

        // POST: BOARD MOVE CARD (Kanban drag & drop — changes the status column)
        if ($method === 'POST' && ($body['api'] ?? '') === 'board' && ($body['action'] ?? '') === 'move_card') {
            if ($role === UserRole::Viewer) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit;
            }

            $boardsCfg = config_get('board') ?? [];
            $boardId   = substr($body['board'] ?? '', 0, 64);
            $boardCfg  = null;
            foreach (($boardsCfg['boards'] ?? []) as $b) {
                if (($b['id'] ?? '') === $boardId) {
                    $boardCfg = $b;
                    break;
                }
            }
            $boardCfg  = $boardCfg ?? [];
            $cfgTable  = $boardCfg['table'] ?? '';
            $statusCol = $boardCfg['status_column'] ?? '';

            // Each board is bound to a single configured table — reject anything else.
            if ($cfgTable === '' || $statusCol === '' || $table !== $cfgTable) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid board table']);
                exit;
            }
            if (!isset($tableCfg['columns'][$statusCol])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status column']);
                exit;
            }

            $id        = (int)($body['id'] ?? 0);
            $newStatus = (string)($body['newStatus'] ?? '');
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid ID']);
                exit;
            }

            // Validate the target lane against the allowed value set so a tampered
            // request cannot write an arbitrary status into the column.
            $statusDef  = $tableCfg['columns'][$statusCol];
            $statusType = strtolower($statusDef['type'] ?? '');
            $allowed    = [];
            if ($statusType === 'enum' && is_array($statusDef['options'] ?? null)) {
                $allowed = array_map('strval', $statusDef['options']);
            } else {
                $sqlD = sprintf(
                    'SELECT DISTINCT %s AS v FROM %s.%s WHERE %s IS NOT NULL',
                    pg_ident($statusCol),
                    pg_ident($schemaName),
                    pg_ident($table),
                    pg_ident($statusCol)
                );
                $rD = @pg_query($conn, $sqlD);
                if ($rD) {
                    while ($r = pg_fetch_assoc($rD)) {
                        $allowed[] = (string)$r['v'];
                    }
                    pg_free_result($rD);
                }
            }
            if (!in_array($newStatus, $allowed, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status value']);
                exit;
            }

            // Owner-restricted: cannot move a record owned by someone else.
            check_record_ownership($conn, $tableCfg, $table, $id, (int)$_SESSION['user_id']);

            $sql = sprintf(
                'UPDATE %s.%s SET %s = $1 WHERE %s = $2',
                pg_ident($schemaName),
                pg_ident($table),
                pg_ident($statusCol),
                pg_ident($idCol)
            );
            $res = @pg_query_params($conn, $sql, [$newStatus, $id]);
            if (!$res) {
                http_response_code(500);
                echo json_encode(['error' => 'Database error']);
                error_log('Board move_card error: ' . pg_last_error($conn));
                exit;
            }
            if (pg_affected_rows($res) === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Record not found']);
                exit;
            }

            log_user_action($conn, (int)$_SESSION['user_id'], 'BOARD_MOVE', $table, $id);
            echo json_encode(['success' => true]);
            exit;
        }

        // PATCH: UPDATE SINGLE CELL
        if ($method === 'PATCH' && isset($body['id'], $body['column'], $body['value'])) {
            $recordId = (int)($body['id']);
            if ($recordId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid record ID']);
                exit;
            }
            $col = $body['column'];
            if (!isset($tableCfg['columns'][$col])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid column specified']);
                exit;
            }

            if ($col === $idCol) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot edit PK']);
                exit;
            }

            check_record_ownership($conn, $tableCfg, $table, $recordId, (int)$_SESSION['user_id'], 'Forbidden: you do not own this record.');

            $colType = strtolower($tableCfg['columns'][$col]['type'] ?? '');
            $cast = '';
            $val = $body['value'];
            if (str_contains($colType, 'bool')) {
                $val = normalize_boolean($val);
                $cast = '::boolean';
            } elseif ($val === '') {
                $val = null;
            }

            // Server-side validation_regexp enforcement — the client data-pattern
            // check is advisory only and trivially bypassed with a direct request.
            if (!str_contains($colType, 'bool') && ($regexpError = validate_column_regexp($tableCfg['columns'][$col], $val)) !== null) {
                http_response_code(422);
                echo json_encode(['error' => $regexpError]);
                exit;
            }

            // Pre-update state for change-based automation conditions (changed_from/changed_to).
            $oldRecord = auto_capture_old_record($conn, $schemaName, $table, $recordId);

            $sql = sprintf('UPDATE %s.%s SET %s = $1%s WHERE %s = $2', pg_ident($schemaName), pg_ident($table), pg_ident($col), $cast, pg_ident($idCol));
            $res = @pg_query_params($conn, $sql, [$val, $recordId]);
            if (!$res) {
                error_log('[api][patch] ' . pg_last_error($conn));
                http_response_code(422);
                echo json_encode(['error' => 'Database error']);
                exit;
            }

            $logId = log_user_action($conn, (int)$_SESSION['user_id'], 'UPDATE', $table, (int)$body['id']);
            if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
                snapshot_record($conn, $schemaName, $table, (int) $body['id'], $logId);
            }
            evaluate_automation_rules($conn, $schemaName, $table, (int)$body['id'], 'update', (int)$_SESSION['user_id'], $oldRecord);
            echo json_encode(['ok' => true]);
            exit;
        }

        // POST: INSERT NEW ROW
        if ($method === 'POST' && isset($body['data'])) {
            $cols = [];
            $vals = [];
            $ph   = [];
            $i    = 1;
            foreach ($tableCfg['columns'] as $colName => $colCfg) {
                if ($colName === $idCol) {
                    continue;
                }

                $type = strtolower($colCfg['type'] ?? '');
                $val = $body['data'][$colName] ?? null;
                if (str_contains($type, 'bool')) {
                    $val = normalize_boolean($val);
                } elseif ($val === '') {
                    $val = null;
                }

                $isNotNull = !empty($colCfg['not_null']);
                if ($val === null && $isNotNull) {
                    $val = type_min_value($type);
                }

                // Server-side validation_regexp enforcement (client check is advisory)
                if (!str_contains($type, 'bool') && ($regexpError = validate_column_regexp($colCfg, $val)) !== null) {
                    http_response_code(422);
                    echo json_encode(['error' => $regexpError, 'column' => $colName]);
                    exit;
                }

                if ($val !== null) {
                    $cols[] = $colName;
                    $vals[] = $val;
                    $ph[]   = str_contains($type, 'bool') ? '$' . $i . '::boolean' : '$' . $i;
                    $i++;
                }
            }

            if (empty($cols)) {
                $sql = sprintf('INSERT INTO %s.%s DEFAULT VALUES RETURNING %s', pg_ident($schemaName), pg_ident($table), pg_ident($idCol));
                $res = @pg_query($conn, $sql);
            } else {
                $sql = sprintf('INSERT INTO %s.%s (%s) VALUES (%s) RETURNING %s', pg_ident($schemaName), pg_ident($table), implode(', ', array_map('pg_ident', $cols)), implode(', ', $ph), pg_ident($idCol));
                $res = @pg_query_params($conn, $sql, $vals);
            }

            if (!$res) {
                error_log('[api][insert] ' . pg_last_error($conn));
                http_response_code(422);
                echo json_encode(['error' => 'Database error']);
                exit;
            }

            $row = pg_fetch_assoc($res);
            pg_free_result($res);
            $newId = $row[$idCol] ?? null;
            if ($newId !== null) {
                $userId = (int)$_SESSION['user_id'];
                $logId  = log_user_action($conn, $userId, 'INSERT', $table, (int)$newId);
                if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
                    snapshot_record($conn, $schemaName, $table, (int) $newId, $logId);
                }
                set_record_owner($conn, $table, (int)$newId, $userId, $userId);
                evaluate_automation_rules($conn, $schemaName, $table, (int)$newId, 'create', $userId);
            }

            echo json_encode(['ok' => true, 'id' => $newId]);
            exit;
        }

        // POST: DUPLICATE ROW
        if ($method === 'POST' && ($body['action'] ?? '') === 'duplicate' && isset($body['id'])) {
            $srcId = (int)$body['id'];
            if ($srcId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid ID']);
                exit;
            }

            $dupCols = [];
            foreach ($tableCfg['columns'] as $colName => $colCfg) {
                if ($colName === $idCol) {
                    continue;
                }
                if (strtolower($colCfg['type'] ?? '') === 'virtual') {
                    continue;
                }
                $dupCols[] = $colName;
            }

            if (empty($dupCols)) {
                http_response_code(422);
                echo json_encode(['error' => 'No columns to duplicate']);
                exit;
            }

            $colIdents = implode(', ', array_map('pg_ident', $dupCols));
            $sql = sprintf('INSERT INTO %s.%s (%s) SELECT %s FROM %s.%s WHERE %s = $1 RETURNING %s', pg_ident($schemaName), pg_ident($table), $colIdents, $colIdents, pg_ident($schemaName), pg_ident($table), pg_ident($idCol), pg_ident($idCol));
            $res = @pg_query_params($conn, $sql, [$srcId]);
            if (!$res) {
                $pgErr = pg_last_error($conn);
                error_log('[api][duplicate] ' . $pgErr);
                http_response_code(422);
                if (stripos($pgErr, 'unique') !== false || stripos($pgErr, 'unikaln') !== false) {
                    $col = '';
                    if (preg_match('/[Kk]ey\s*\(([^)]+)\)|Klucz\s*\(([^)]+)\)/', $pgErr, $m)) {
                            $col = $m[1] ?: $m[2];
                    }
                    $msg = $col
                        ? t('grid.duplicate_unique', ['col' => $col])
                        : t('grid.duplicate_conflict');
                    echo json_encode(['error' => $msg]);
                } else {
                    echo json_encode(['error' => 'Database error']);
                }
                exit;
            }

            $row = pg_fetch_assoc($res);
            pg_free_result($res);
            $newId = $row[$idCol] ?? null;
            if ($newId !== null) {
                $userId = (int)$_SESSION['user_id'];
                $logId  = log_user_action($conn, $userId, 'INSERT', $table, (int)$newId);
                if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
                    snapshot_record($conn, $schemaName, $table, (int)$newId, $logId);
                }
                set_record_owner($conn, $table, (int)$newId, $userId, $userId);
            }

            echo json_encode(['ok' => true, 'id' => $newId]);
            exit;
        }

        // DELETE: REMOVE ROW
        if ($method === 'DELETE' && isset($body['id'])) {
            $deleteId = (int)$body['id'];
            if ($deleteId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid record ID']);
                exit;
            }

            check_record_ownership($conn, $tableCfg, $table, $deleteId, (int)$_SESSION['user_id'], 'Forbidden: you do not own this record.');

            $sql = sprintf('DELETE FROM %s.%s WHERE %s=$1', pg_ident($schemaName), pg_ident($table), pg_ident($idCol));
            $res = @pg_query_params($conn, $sql, [$deleteId]);
            if (!$res) {
                error_log('[api][delete] ' . pg_last_error($conn));
                http_response_code(422);
                echo json_encode(['error' => 'Database error']);
                exit;
            }

            log_user_action($conn, (int)$_SESSION['user_id'], 'DELETE', $table, $deleteId);
            evaluate_automation_rules($conn, $schemaName, $table, $deleteId, 'delete', (int)$_SESSION['user_id']);
            echo json_encode(['ok' => true]);
            exit;
        }
    }
} catch (Throwable $e) {
    error_log('[api][exception] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
}
