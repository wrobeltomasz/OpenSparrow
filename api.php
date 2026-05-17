<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

// Guarded session start — this file is both called directly (e.g. PATCH from
// inline grid edits) and require'd from index.php, which already calls
// session_start(). Without the guard, PHP emits an "Ignoring session_start()"
// Notice that gets echoed as HTML before the JSON body, silently corrupting
// every response (client res.json() throws, deleteRow returns null, the grid
// refresh after DELETE never runs, etc.).
// Cookie params must match dashboard.php / login.php so session ID resolves to
// the same save_path entry — without config.php normalising save_path first,
// PHP-FPM falls back to its working dir and the lookup misses, returning 401.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => SECURE_COOKIES,
        'httponly' => true,
        'samesite' => SESSION_SAMESITE,
    ]);
    session_start();
}

// Block access without active session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$method = $_SERVER['REQUEST_METHOD'];
$role = $_SESSION['role'] ?? 'viewer';

// Validate CSRF token for all state-changing requests
if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        exit(json_encode(['error' => 'CSRF token mismatch.']));
    }
}

// Self-service profile actions — permitted for every authenticated user regardless of role
$profileAction = $_GET['action'] ?? '';
if (in_array($profileAction, ['update_avatar', 'change_password'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    require __DIR__ . '/includes/db.php';
    $conn = db_connect();
    require __DIR__ . '/includes/api_helpers.php';

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
        $newOptions = ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 2];
        $newHash    = password_hash($newSalt . $new, PASSWORD_ARGON2ID, $newOptions);

        $sqlUpd = 'UPDATE ' . sys_table('users')
            . ' SET password_hash = $1, salt = $2, password_algo = $3, password_params = $4 WHERE id = $5';
        $params = [
            $newHash,
            $newSalt,
            'argon2id',
            json_encode(['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 2]),
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

// Admin role is restricted to the admin panel; block from frontend data API
if ($role === 'admin') {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden: Admin accounts cannot access the frontend data API.']));
}

// Block data modification requests for viewer users
if ($role === 'viewer' && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden: Read-only access']));
}

// Load schema
$schemaPath = __DIR__ . '/config/schema.json';
$schemaJson = file_get_contents($schemaPath);
if ($schemaJson === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot read schema.json']);
    exit;
}
$schema = json_decode($schemaJson, true, 512, JSON_THROW_ON_ERROR);

// Connect to DB and load helpers
require __DIR__ . '/includes/db.php';
$conn = db_connect();
require __DIR__ . '/includes/api_helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json; charset=utf-8');

try {
    // GET: SCHEMA DATA
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'schema') {
        echo $schemaJson;
        exit;
    }

    // GET: WORKFLOWS DATA
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'workflows') {
        $wfPath = __DIR__ . '/config/workflows.json';
        if (!file_exists($wfPath)) {
            echo json_encode(['menu_name' => 'Workflows', 'workflows' => []]);
            exit;
        }

        $wfJson = file_get_contents($wfPath);
        $workflows = json_decode($wfJson, true, 512, JSON_THROW_ON_ERROR);

        echo json_encode($workflows);
        exit;
    }

    // GET: DASHBOARD DATA
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'dashboard') {
        $dashPath = __DIR__ . '/config/dashboard.json';
        if (!file_exists($dashPath)) {
            echo json_encode(['layout' => [], 'widgets' => []]);
            exit;
        }

        $dashJson = file_get_contents($dashPath);
        $dashboard = json_decode($dashJson, true, 512, JSON_THROW_ON_ERROR);

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
            $condParts = [];
            $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'ILIKE', 'IS NULL', 'IS NOT NULL'];
            foreach ($conditions as $cond) {
                $col = $cond['col'] ?? '';
                $op  = $cond['op']  ?? '=';
                $val = (string)($cond['val'] ?? '');
                if (!isset($tableCfg['columns'][$col])) continue;
                if (!in_array($op, $allowedOps, true)) continue;
                $colSql = pg_ident($col);
                if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                    $condParts[] = [$colSql . ' ' . $op, strtoupper($cond['logic'] ?? 'AND')];
                } else {
                    $condParts[] = [$colSql . ' ' . $op . " '" . pg_escape_string($conn, $val) . "'", strtoupper($cond['logic'] ?? 'AND')];
                }
            }
            if (!empty($condParts)) {
                $built = $condParts[0][0];
                for ($i = 1; $i < count($condParts); $i++) {
                    $built .= ' ' . $condParts[$i][1] . ' ' . $condParts[$i][0];
                }
                $sqlWhere = ' WHERE ' . $built;
            }

            // Apply Global Date Filter if requested and target matches
            $dateFilter = $_GET['date_filter'] ?? 'all';
            $dateTarget = $_GET['date_target'] ?? 'all';
            $widgetTargetId = $widget['id'] ?? $widget['table'] ?? '';
            
            if ($dateFilter !== 'all' && ($dateTarget === 'all' || $dateTarget === $widgetTargetId)) {
                $dateCol = null;
                // Find the first column that represents a date or timestamp
                foreach ($tableCfg['columns'] as $cName => $cCfg) {
                    $cType = strtolower($cCfg['type'] ?? '');
                    if (str_contains($cType, 'date') || str_contains($cType, 'time') || str_contains($cType, 'timestamp')) {
                        $dateCol = $cName;
                        break;
                    }
                }

                if ($dateCol) {
                    $prefix = $sqlWhere === '' ? ' WHERE ' : ' AND ';
                    if ($dateFilter === 'today') {
                        $sqlWhere .= $prefix . pg_ident($dateCol) . " >= CURRENT_DATE";
                    } elseif ($dateFilter === '7d') {
                        $sqlWhere .= $prefix . pg_ident($dateCol) . " >= CURRENT_DATE - INTERVAL '7 days'";
                    } elseif ($dateFilter === '30d') {
                        $sqlWhere .= $prefix . pg_ident($dateCol) . " >= CURRENT_DATE - INTERVAL '30 days'";
                    } elseif ($dateFilter === 'this_month') {
                        $sqlWhere .= $prefix . "DATE_TRUNC('month', " . pg_ident($dateCol) . ") = DATE_TRUNC('month', CURRENT_DATE)";
                    }
                }
            }

            if ($qType === 'count') {
                $col = $widget['query']['column'] ?? id_column();
                if (isset($tableCfg['columns'][$col]) || $col === id_column()) {
                    $sql = sprintf(
                        'SELECT COUNT(%s) AS count FROM "%s"."%s"%s',
                        pg_ident($col),
                        $schemaName,
                        $table,
                        $sqlWhere
                    );
                    
                    // Supress warnings with at symbol to prevent HTML breaking JSON response
                    $res = @pg_query($conn, $sql);
                    if ($res) {
                        $row = pg_fetch_assoc($res);
                        $data = (int)($row['count'] ?? 0);
                        pg_free_result($res);
                    } else {
                        $widget['sql_error'] = pg_last_error($conn);
                    }
                }
            } elseif ($qType === 'sum') {
                $col = $widget['query']['column'] ?? '';
                if (isset($tableCfg['columns'][$col])) {
                    $sql = sprintf(
                        'SELECT COALESCE(SUM(%s), 0) AS total FROM "%s"."%s"%s',
                        pg_ident($col),
                        $schemaName,
                        $table,
                        $sqlWhere
                    );
                    $res = @pg_query($conn, $sql);
                    if ($res) {
                        $row = pg_fetch_assoc($res);
                        $val = (float)($row['total'] ?? 0);
                        $data = ($val == (int)$val) ? (int)$val : round($val, 2);
                        pg_free_result($res);
                    } else {
                        $widget['sql_error'] = pg_last_error($conn);
                    }
                }
            } elseif ($qType === 'group_by') {
                $grpCol = $widget['query']['group_column'] ?? '';
                $aggCol = $widget['query']['agg_column'] ?? id_column();
                $aggType = strtoupper($widget['query']['agg_type'] ?? 'COUNT');
                $allowedAgg = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
                $aggType = in_array($aggType, $allowedAgg, true) ? $aggType : 'COUNT';

                if (isset($tableCfg['columns'][$grpCol])) {
                    $sql = sprintf(
                        'SELECT %s AS label, %s(%s) AS value FROM "%s"."%s"%s GROUP BY %s ORDER BY value DESC',
                        pg_ident($grpCol),
                        $aggType,
                        pg_ident($aggCol),
                        $schemaName,
                        $table,
                        $sqlWhere,
                        pg_ident($grpCol)
                    );
                    
                    $res = @pg_query($conn, $sql);
                    if ($res) {
                        $data = [];
                        while ($r = pg_fetch_assoc($res)) {
                            $r['value'] = is_numeric($r['value']) ? (float)$r['value'] : $r['value'];
                            $data[] = $r;
                        }
                        pg_free_result($res);
                    } else {
                        $widget['sql_error'] = pg_last_error($conn);
                    }
                }
            } else {
                $limit = (int)($widget['query']['limit'] ?? 5);
                $orderBy = $widget['query']['order_by'] ?? id_column();
                $dir = strtoupper($widget['query']['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
                $displayCols = $widget['display_columns'] ?? [id_column()];

                $validCols = array_filter(
                    $displayCols,
                    fn($c) => isset($tableCfg['columns'][$c]) || $c === id_column()
                );

                if (empty($validCols)) {
                    $validCols = [id_column()];
                }

                $selectSql = implode(', ', array_map('pg_ident', $validCols));

                if (isset($tableCfg['columns'][$orderBy]) || $orderBy === id_column()) {
                    $sql = sprintf(
                        'SELECT %s FROM "%s"."%s"%s ORDER BY %s %s LIMIT %d',
                        $selectSql,
                        $schemaName,
                        $table,
                        $sqlWhere,
                        pg_ident($orderBy),
                        $dir,
                        $limit
                    );
                    
                    $res = @pg_query($conn, $sql);
                    if ($res) {
                        $data = [];
                        while ($r = pg_fetch_assoc($res)) {
                            $data[] = $r;
                        }
                        pg_free_result($res);
                    } else {
                        $widget['sql_error'] = pg_last_error($conn);
                    }
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
        $calPath = __DIR__ . '/config/calendar.json';
        if (!file_exists($calPath)) {
            echo json_encode(['events' => []]);
            exit;
        }

        $calJson = file_get_contents($calPath);
        $calendar = json_decode($calJson, true, 512, JSON_THROW_ON_ERROR);
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
                    'SELECT %s FROM "%s"."%s" WHERE %s IS NOT NULL',
                    $selectSql,
                    $schemaName,
                    $table,
                    pg_ident($dateCol)
                );
                $res = @pg_query($conn, $sql);

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

        if (!$jt || !$selfFk || !$otherFk || !$otherTable
            || !isset($schema['tables'][$jt], $schema['tables'][$otherTable])) {
            exit(json_encode(['data' => (object)[]]));
        }

        $jtSchema = $schema['tables'][$jt]['schema']         ?? 'public';
        $otSchema = $schema['tables'][$otherTable]['schema'] ?? 'public';

        $placeholders = implode(',', array_map(fn($i) => '$' . ($i + 1), array_keys($ids)));

        $sql = sprintf(
            'SELECT j.%s AS sid, o.%s AS label
               FROM "%s"."%s" j
               JOIN "%s"."%s" o ON o."id" = j.%s
              WHERE j.%s IN (%s)
              ORDER BY j.%s, o.%s',
            pg_ident($selfFk), pg_ident($displayCol),
            $jtSchema, $jt,
            $otSchema, $otherTable,
            pg_ident($otherFk),
            pg_ident($selfFk), $placeholders,
            pg_ident($selfFk), pg_ident($displayCol)
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

        $filterCol = $_GET['filter_col'] ?? '';
        $filterVal = $_GET['filter_val'] ?? '';

        $whereSql = '';
        $params = [];

        if ($filterCol !== '' && $filterVal !== '') {
            $whereSql = sprintf(' WHERE %s = $1', pg_ident($filterCol));
            $params[] = $filterVal;
        }

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
        $limitSql     = $initialLimit > 0 ? ' LIMIT ' . $initialLimit : '';

        $sql = sprintf(
            'SELECT %s FROM "%s"."%s"%s ORDER BY %s%s',
            $selectSql,
            $schemaName,
            $table,
            $whereSql,
            implode(', ', $orderClauses),
            $limitSql
        );

        $res = @pg_query_params($conn, $sql, $params);

        if (!$res) {
            error_log('[api][list] ' . pg_last_error($conn));
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
            exit;
        }

        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $rows[] = $r;
        }
        pg_free_result($res);
        $rows = map_fk_display($schema, $tableCfg, $rows);

        echo json_encode([
            'columns'   => $selectCols,
            'rows'      => $rows,
            'truncated' => $initialLimit > 0 && count($rows) === $initialLimit,
            'table'     => [
                'name'         => $table,
                'display_name' => to_display_name($tableCfg),
            ],
        ]);
        exit;
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
            if ($role === 'viewer') {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit;
            }

            // Load calendar configuration to validate source tables
            $calPath = __DIR__ . '/config/calendar.json';
            $calConfig = file_exists($calPath) ? json_decode(file_get_contents($calPath), true) : ['sources' => []];
            $sources = $calConfig['sources'] ?? [];

            // Whitelist payload table against calendar.json sources
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
            $sql = sprintf(
                'UPDATE "%s"."%s" SET "%s" = $1 WHERE %s = $2',
                $schemaName,
                $table,
                $dateColumn,
                pg_ident($idCol)
            );

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

            echo json_encode(['success' => true]);
            exit;
        }

        // PATCH: UPDATE SINGLE CELL
        if ($method === 'PATCH' && isset($body['id'], $body['column'], $body['value'])) {
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

            $colType = strtolower($tableCfg['columns'][$col]['type'] ?? '');
            $cast = '';
            $val = $body['value'];

            if (str_contains($colType, 'bool')) {
                $val = normalize_boolean($val);
                $cast = '::boolean';
            } elseif ($val === '') {
                $val = null;
            }

            $sql = sprintf(
                'UPDATE "%s"."%s" SET %s = $1%s WHERE %s = $2',
                $schemaName,
                $table,
                pg_ident($col),
                $cast,
                pg_ident($idCol)
            );

            $res = @pg_query_params($conn, $sql, [$val, $body['id']]);
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

                if ($val !== null) {
                    $cols[] = $colName;
                    $vals[] = $val;
                    $ph[]   = str_contains($type, 'bool') ? '$' . $i . '::boolean' : '$' . $i;
                    $i++;
                }
            }

            if (empty($cols)) {
                $sql = sprintf(
                    'INSERT INTO "%s"."%s" DEFAULT VALUES RETURNING %s',
                    $schemaName,
                    $table,
                    pg_ident($idCol)
                );
                $res = @pg_query($conn, $sql);
            } else {
                $sql = sprintf(
                    'INSERT INTO "%s"."%s" (%s) VALUES (%s) RETURNING %s',
                    $schemaName,
                    $table,
                    implode(', ', array_map('pg_ident', $cols)),
                    implode(', ', $ph),
                    pg_ident($idCol)
                );
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
            $sql = sprintf(
                'INSERT INTO "%s"."%s" (%s) SELECT %s FROM "%s"."%s" WHERE %s = $1 RETURNING %s',
                $schemaName,
                $table,
                $colIdents,
                $colIdents,
                $schemaName,
                $table,
                pg_ident($idCol),
                pg_ident($idCol)
            );

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
                        ? "Duplicate failed: column \"$col\" must be unique. Edit it in the new record."
                        : 'Duplicate failed: a unique column conflict occurred. Edit the value in the new record.';
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
            $sql = sprintf(
                'DELETE FROM "%s"."%s" WHERE %s=$1',
                $schemaName,
                $table,
                pg_ident($idCol)
            );

            $res = @pg_query_params($conn, $sql, [$body['id']]);
            if (!$res) {
                error_log('[api][delete] ' . pg_last_error($conn));
                http_response_code(422);
                echo json_encode(['error' => 'Database error']);
                exit;
            }

            log_user_action($conn, (int)$_SESSION['user_id'], 'DELETE', $table, (int)$body['id']);
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