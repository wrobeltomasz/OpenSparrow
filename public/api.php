<?php

declare(strict_types=1);

// api.php — Main CRUD/data REST API for the frontend (core data endpoint, largest file)
// Auth gate: session + hard lifetime/UA enforcement + CSRF for POST/PATCH/DELETE; admin blocked, viewer read-only
// Routes by HTTP method against config/schema.json tables; also self-service profile actions (update_avatar, change_password), i18n_bundle, calendar/board move, mass insert
// Records routed to PostgreSQL or MySQL gateway; every write does log_user_action() audit, snapshot_record(), and automations (automations.php)
// All identifiers via pg_ident(), values parameterized; uses sys_table() for system tables

use App\Security\UserRole;

require_once __DIR__ . '/../includes/bootstrap.php';

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

// Load schema
$schemaPath = __DIR__ . '/../config/schema.json';
$schemaJson = file_get_contents($schemaPath);
if ($schemaJson === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot read schema.json']);
    exit;
}
$schema = json_decode($schemaJson, true, 512, JSON_THROW_ON_ERROR);
// Connect to DB (db.php + api_helpers.php are already loaded by the bootstrap)
$conn = db_connect();
require_once __DIR__ . '/../includes/automations.php';

// ---------------------------------------------------------------------------
// MySQL Gateway helpers — load routing config and create PDO once per request
// ---------------------------------------------------------------------------
function mysql_gateway_tables(): array
{
    static $tables = null;
    if ($tables === null) {
        $path   = __DIR__ . '/../config/mysql_gateway.json';
        $raw    = is_file($path) ? @file_get_contents($path) : false;
        $cfg    = ($raw !== false) ? json_decode($raw, true) : null;
        $tables = is_array($cfg) ? ($cfg['mysql_tables'] ?? []) : [];
    }
    return $tables;
}

// MySQL Gateway PDO + identifier quoting live in the shared includes/mysql.php module
require_once __DIR__ . '/../includes/mysql.php';

/**
 * Build a MySQL WHERE clause (with `?` placeholders) for dashboard widgets,
 * mirroring the PostgreSQL builder used for native tables. Validates every
 * column against the table schema and binds values as parameters. The id
 * column is mapped back to its real MySQL primary key when aliased via
 * `mysql_pk`. Appended params are pushed onto $params by reference.
 * $window selects the date range: 'current' for the active filter window,
 * 'prev' for the equally long window directly before it (stat card trend deltas).
 * Returns null for $window = 'prev' when no date filter applies to the widget.
 */
function mysql_widget_where(
    array $tableCfg,
    array $conditions,
    string $dateFilter,
    string $dateTarget,
    string $widgetTargetId,
    array &$params,
    string $window = 'current'
): ?string {
    $idCol   = id_column();
    $mysqlPk = $tableCfg['mysql_pk'] ?? null;
    $realCol = fn(string $c): string => ($c === $idCol && $mysqlPk !== null) ? $mysqlPk : $c;
    $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'ILIKE', 'IS NULL', 'IS NOT NULL'];

    $condParts = [];
    foreach ($conditions as $cond) {
        $col = $cond['col'] ?? '';
        $op  = $cond['op']  ?? '=';
        $val = (string)($cond['val'] ?? '');
        if (!isset($tableCfg['columns'][$col]) || !in_array($op, $allowedOps, true)) {
            continue;
        }
        $logic  = strtoupper($cond['logic'] ?? 'AND') === 'OR' ? 'OR' : 'AND';
        // MySQL LIKE is case-insensitive on standard collations — map ILIKE to LIKE.
        $myOp   = $op === 'ILIKE' ? 'LIKE' : $op;
        $colSql = mysql_bt($realCol($col));
        if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
            $condParts[] = [$colSql . ' ' . $op, $logic];
        } else {
            $condParts[] = [$colSql . ' ' . $myOp . ' ?', $logic];
            $params[]    = $val;
        }
    }

    // Parenthesise the whole condition group so an appended date-range AND
    // cannot rebind a widget-level OR (AND binds tighter than OR in SQL).
    $condSql = '';
    if (!empty($condParts)) {
        $built = $condParts[0][0];
        for ($i = 1, $n = count($condParts); $i < $n; $i++) {
            $built .= ' ' . $condParts[$i][1] . ' ' . $condParts[$i][0];
        }
        $condSql = count($condParts) > 1 ? '(' . $built . ')' : $built;
    }

    $dateSql = null;
    if ($dateFilter !== 'all' && ($dateTarget === 'all' || $dateTarget === $widgetTargetId)) {
        // First column that represents a date/time ('time' also matches 'timestamp')
        $dateCol = array_find_key($tableCfg['columns'], static function (array $cCfg): bool {
            $cType = strtolower($cCfg['type'] ?? '');
            return str_contains($cType, 'date') || str_contains($cType, 'time');
        });
        if ($dateCol !== null) {
            $dc = mysql_bt($realCol($dateCol));
            $dateSql = $window === 'prev'
                ? match ($dateFilter) {
                    'today'      => '(' . $dc . ' >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND ' . $dc . ' < CURDATE())',
                    '7d'         => '(' . $dc . ' >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND ' . $dc . ' < DATE_SUB(CURDATE(), INTERVAL 7 DAY))',
                    '30d'        => '(' . $dc . ' >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND ' . $dc . ' < DATE_SUB(CURDATE(), INTERVAL 30 DAY))',
                    'this_month' => "DATE_FORMAT(" . $dc . ", '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')",
                    default      => null,
                }
                : match ($dateFilter) {
                    'today'      => $dc . ' >= CURDATE()',
                    '7d'         => $dc . ' >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
                    '30d'        => $dc . ' >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
                    'this_month' => "DATE_FORMAT(" . $dc . ", '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
                    default      => null,
                };
        }
    }

    if ($window === 'prev' && $dateSql === null) {
        return null;
    }

    $parts = array_values(array_filter([$condSql, $dateSql ?? '']));
    return empty($parts) ? '' : ' WHERE ' . implode(' AND ', $parts);
}

try {
// GET: SCHEMA DATA
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'schema') {
        echo $schemaJson;
        exit;
    }

    // GET: WORKFLOWS DATA
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'workflows') {
        $wfPath = __DIR__ . '/../config/workflows.json';
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
        $dashPath = __DIR__ . '/../config/dashboard.json';
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

            // External MySQL tables don't exist in PostgreSQL — route the widget
            // query through the MySQL gateway instead of pg_query.
            if (in_array($table, mysql_gateway_tables(), true)) {
                $mysqlPdo = mysql_pdo('api');
                if ($mysqlPdo === null) {
                    $widget['sql_error'] = 'MySQL connection unavailable.';
                    $widget['data'] = null;
                    $response['widgets'][] = $widget;
                    continue;
                }
                $idColMy   = id_column();
                $mysqlPkMy = $tableCfg['mysql_pk'] ?? null;
                $realColMy = fn(string $c): string => ($c === $idColMy && $mysqlPkMy !== null) ? $mysqlPkMy : $c;
                $myTable   = mysql_bt($table);

                $dfFilter  = $_GET['date_filter'] ?? 'all';
                $dfTarget  = $_GET['date_target'] ?? 'all';
                $wTargetId = $widget['id'] ?? $widget['table'] ?? '';
                $myParams  = [];
                $myWhere   = mysql_widget_where($tableCfg, $conditions, $dfFilter, $dfTarget, $wTargetId, $myParams);
                // Previous-period window for stat card trend deltas (null when no date filter applies)
                $myParamsPrev = [];
                $myWherePrev  = mysql_widget_where($tableCfg, $conditions, $dfFilter, $dfTarget, $wTargetId, $myParamsPrev, 'prev');

                $data = null;
                try {
                    if ($qType === 'count') {
                        $col = $widget['query']['column'] ?? $idColMy;
                        if (isset($tableCfg['columns'][$col]) || $col === $idColMy) {
                            $stmt = $mysqlPdo->prepare('SELECT COUNT(' . mysql_bt($realColMy($col)) . ') AS count FROM ' . $myTable . $myWhere);
                            $stmt->execute($myParams);
                            $data = (int) $stmt->fetchColumn();
                            if ($myWherePrev !== null) {
                                $stmt = $mysqlPdo->prepare('SELECT COUNT(' . mysql_bt($realColMy($col)) . ') AS count FROM ' . $myTable . $myWherePrev);
                                $stmt->execute($myParamsPrev);
                                $widget['prev_data'] = (int) $stmt->fetchColumn();
                            }
                        }
                    } elseif ($qType === 'sum') {
                        $col = $widget['query']['column'] ?? '';
                        if (isset($tableCfg['columns'][$col])) {
                            $stmt = $mysqlPdo->prepare('SELECT COALESCE(SUM(' . mysql_bt($realColMy($col)) . '), 0) AS total FROM ' . $myTable . $myWhere);
                            $stmt->execute($myParams);
                            $val  = (float) $stmt->fetchColumn();
                            $data = ($val == (int) $val) ? (int) $val : round($val, 2);
                            if ($myWherePrev !== null) {
                                $stmt = $mysqlPdo->prepare('SELECT COALESCE(SUM(' . mysql_bt($realColMy($col)) . '), 0) AS total FROM ' . $myTable . $myWherePrev);
                                $stmt->execute($myParamsPrev);
                                $valP = (float) $stmt->fetchColumn();
                                $widget['prev_data'] = ($valP == (int) $valP) ? (int) $valP : round($valP, 2);
                            }
                        }
                    } elseif ($qType === 'group_by') {
                        $grpCol  = $widget['query']['group_column'] ?? '';
                        $aggCol  = $widget['query']['agg_column'] ?? $idColMy;
                        $aggType = strtoupper($widget['query']['agg_type'] ?? 'COUNT');
                        $allowedAgg = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
                        $aggType = in_array($aggType, $allowedAgg, true) ? $aggType : 'COUNT';
                        if (isset($tableCfg['columns'][$grpCol])) {
                            $sql = sprintf(
                                'SELECT %s AS label, %s(%s) AS value FROM %s%s GROUP BY %s ORDER BY value DESC',
                                mysql_bt($realColMy($grpCol)),
                                $aggType,
                                mysql_bt($realColMy($aggCol)),
                                $myTable,
                                $myWhere,
                                mysql_bt($realColMy($grpCol))
                            );
                            $stmt = $mysqlPdo->prepare($sql);
                            $stmt->execute($myParams);
                            $data = [];
                            while ($r = $stmt->fetch()) {
                                $r['value'] = is_numeric($r['value']) ? (float) $r['value'] : $r['value'];
                                $data[] = $r;
                            }
                            $widget['column_type'] = $tableCfg['columns'][$grpCol]['type'] ?? 'text';
                        }
                    } else {
                        $limit   = (int)($widget['query']['limit'] ?? 5);
                        $orderBy = $widget['query']['order_by'] ?? $idColMy;
                        $dir     = strtoupper($widget['query']['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
                        $displayCols = $widget['display_columns'] ?? [$idColMy];
                        $validCols   = array_filter($displayCols, fn($c) => isset($tableCfg['columns'][$c]) || $c === $idColMy);
                        if (empty($validCols)) {
                            $validCols = [$idColMy];
                        }
                        $selectSql = implode(', ', array_map(
                            fn($c) => ($c === $idColMy && $mysqlPkMy !== null)
                                ? mysql_bt($mysqlPkMy) . ' AS ' . mysql_bt($idColMy)
                                : mysql_bt($c),
                            $validCols
                        ));
                        if (isset($tableCfg['columns'][$orderBy]) || $orderBy === $idColMy) {
                            $sql  = sprintf(
                                'SELECT %s FROM %s%s ORDER BY %s %s LIMIT %d',
                                $selectSql,
                                $myTable,
                                $myWhere,
                                mysql_bt($realColMy($orderBy)),
                                $dir,
                                $limit
                            );
                            $stmt = $mysqlPdo->prepare($sql);
                            $stmt->execute($myParams);
                            $data = $stmt->fetchAll();
                            $colTypes = [];
                            foreach ($validCols as $col) {
                                $colTypes[$col] = $tableCfg['columns'][$col]['type'] ?? 'text';
                            }
                            $widget['column_types'] = $colTypes;
                        }
                    }
                } catch (\PDOException $e) {
                    error_log('[api][mysql][dashboard] ' . $e->getMessage());
                    $widget['sql_error'] = 'Query failed.';
                }

                $widget['data'] = $data;
                $response['widgets'][] = $widget;
                continue;
            }

            $condParts = [];
            $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'ILIKE', 'IS NULL', 'IS NOT NULL'];
            foreach ($conditions as $cond) {
                $col = $cond['col'] ?? '';
                $op  = $cond['op']  ?? '=';
                $val = (string)($cond['val'] ?? '');
                if (!isset($tableCfg['columns'][$col])) {
                    continue;
                }
                if (!in_array($op, $allowedOps, true)) {
                    continue;
                }
                $colSql = pg_ident($col);
                // Constrain the boolean connective to a strict allowlist — it is
                // concatenated into SQL below, so never trust the raw config value.
                $logic = strtoupper($cond['logic'] ?? 'AND') === 'OR' ? 'OR' : 'AND';
                if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                    $condParts[] = [$colSql . ' ' . $op, $logic];
                } else {
                    $condParts[] = [$colSql . ' ' . $op . " '" . pg_escape_string($conn, $val) . "'", $logic];
                }
            }
            // Parenthesise the whole condition group so the appended date-range AND
            // cannot rebind a widget-level OR (AND binds tighter than OR in SQL).
            $condSql = '';
            if (!empty($condParts)) {
                $built = $condParts[0][0];
                for ($i = 1; $i < count($condParts); $i++) {
                    $built .= ' ' . $condParts[$i][1] . ' ' . $condParts[$i][0];
                }
                $condSql = count($condParts) > 1 ? '(' . $built . ')' : $built;
            }

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

            if ($qType === 'count') {
                $col = $widget['query']['column'] ?? id_column();
                if (isset($tableCfg['columns'][$col]) || $col === id_column()) {
                    $sql = sprintf('SELECT COUNT(%s) AS count FROM %s.%s%s', pg_ident($col), pg_ident($schemaName), pg_ident($table), $sqlWhere);
        // Supress warnings with at symbol to prevent HTML breaking JSON response
                    $res = @pg_query($conn, $sql);
                    if ($res) {
                        $row = pg_fetch_assoc($res);
                        $data = (int)($row['count'] ?? 0);
                        pg_free_result($res);
                        if ($sqlWherePrev !== null) {
                            $sqlPrev = sprintf('SELECT COUNT(%s) AS count FROM %s.%s%s', pg_ident($col), pg_ident($schemaName), pg_ident($table), $sqlWherePrev);
                            $resP = @pg_query($conn, $sqlPrev);
                            if ($resP) {
                                $rowP = pg_fetch_assoc($resP);
                                $widget['prev_data'] = (int)($rowP['count'] ?? 0);
                                pg_free_result($resP);
                            }
                        }
                    } else {
                        $widget['sql_error'] = 'Query failed.';
                    }
                }
            } elseif ($qType === 'sum') {
                $col = $widget['query']['column'] ?? '';
                if (isset($tableCfg['columns'][$col])) {
                    $sql = sprintf('SELECT COALESCE(SUM(%s), 0) AS total FROM %s.%s%s', pg_ident($col), pg_ident($schemaName), pg_ident($table), $sqlWhere);
                    $res = @pg_query($conn, $sql);
                    if ($res) {
                        $row = pg_fetch_assoc($res);
                        $val = (float)($row['total'] ?? 0);
                        $data = ($val == (int)$val) ? (int)$val : round($val, 2);
                        pg_free_result($res);
                        if ($sqlWherePrev !== null) {
                            $sqlPrev = sprintf('SELECT COALESCE(SUM(%s), 0) AS total FROM %s.%s%s', pg_ident($col), pg_ident($schemaName), pg_ident($table), $sqlWherePrev);
                            $resP = @pg_query($conn, $sqlPrev);
                            if ($resP) {
                                $rowP = pg_fetch_assoc($resP);
                                $valP = (float)($rowP['total'] ?? 0);
                                $widget['prev_data'] = ($valP == (int)$valP) ? (int)$valP : round($valP, 2);
                                pg_free_result($resP);
                            }
                        }
                    } else {
                        $widget['sql_error'] = 'Query failed.';
                    }
                }
            } elseif ($qType === 'group_by') {
                $grpCol = $widget['query']['group_column'] ?? '';
                $aggCol = $widget['query']['agg_column'] ?? id_column();
                $aggType = strtoupper($widget['query']['agg_type'] ?? 'COUNT');
                $allowedAgg = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
                $aggType = in_array($aggType, $allowedAgg, true) ? $aggType : 'COUNT';
                if (isset($tableCfg['columns'][$grpCol])) {
                    $sql = sprintf('SELECT %s AS label, %s(%s) AS value FROM %s.%s%s GROUP BY %s ORDER BY value DESC', pg_ident($grpCol), $aggType, pg_ident($aggCol), pg_ident($schemaName), pg_ident($table), $sqlWhere, pg_ident($grpCol));
                    $res = @pg_query($conn, $sql);
                    if ($res) {
                        $data = [];
                        while ($r = pg_fetch_assoc($res)) {
                            $r['value'] = is_numeric($r['value']) ? (float)$r['value'] : $r['value'];
                            $data[] = $r;
                        }
                        pg_free_result($res);
                        $widget['column_type'] = $tableCfg['columns'][$grpCol]['type'] ?? 'text';
                    } else {
                        $widget['sql_error'] = 'Query failed.';
                    }
                }
            } else {
                $limit = (int)($widget['query']['limit'] ?? 5);
                $orderBy = $widget['query']['order_by'] ?? id_column();
                $dir = strtoupper($widget['query']['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
                $displayCols = $widget['display_columns'] ?? [id_column()];
                $validCols = array_filter($displayCols, fn($c) => isset($tableCfg['columns'][$c]) || $c === id_column());
                if (empty($validCols)) {
                    $validCols = [id_column()];
                }

                $selectSql = implode(', ', array_map('pg_ident', $validCols));
                if (isset($tableCfg['columns'][$orderBy]) || $orderBy === id_column()) {
                    $sql = sprintf('SELECT %s FROM %s.%s%s ORDER BY %s %s LIMIT %d', $selectSql, pg_ident($schemaName), pg_ident($table), $sqlWhere, pg_ident($orderBy), $dir, $limit);
                    $res = @pg_query($conn, $sql);
                    if ($res) {
                        $data = [];
                        while ($r = pg_fetch_assoc($res)) {
                            $data[] = $r;
                        }
                        pg_free_result($res);
                        $colTypes = [];
                        foreach ($validCols as $col) {
                            $colTypes[$col] = $tableCfg['columns'][$col]['type'] ?? 'text';
                        }
                        $widget['column_types'] = $colTypes;
                    } else {
                        $widget['sql_error'] = 'Query failed.';
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
        $calPath = __DIR__ . '/../config/calendar.json';
        if (!file_exists($calPath)) {
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

                // External MySQL tables don't exist in PostgreSQL — pull events
                // through the MySQL gateway instead of pg_query_params.
                if (in_array($table, mysql_gateway_tables(), true)) {
                    $mysqlPdo = mysql_pdo('api');
                    if ($mysqlPdo === null) {
                        continue;
                    }
                    $mysqlPk = $tableCfg['mysql_pk'] ?? null;
                    $realCol = fn(string $c): string => ($c === $idCol && $mysqlPk !== null) ? $mysqlPk : $c;
                    $mySelect = implode(', ', array_map(
                        fn($c) => ($c === $idCol && $mysqlPk !== null)
                            ? mysql_bt($mysqlPk) . ' AS ' . mysql_bt($idCol)
                            : mysql_bt($c),
                        $selectCols
                    ));
                    // Inclusive end-of-range so datetime columns keep events on
                    // the last day of the month (a plain BETWEEN would cut them at 00:00).
                    $sql = sprintf(
                        'SELECT %s FROM %s WHERE %s IS NOT NULL AND %s >= ? AND %s < DATE_ADD(?, INTERVAL 1 DAY)',
                        $mySelect,
                        mysql_bt($table),
                        mysql_bt($realCol($dateCol)),
                        mysql_bt($realCol($dateCol)),
                        mysql_bt($realCol($dateCol))
                    );
                    try {
                        $stmt = $mysqlPdo->prepare($sql);
                        $stmt->execute([$dateFrom, $dateTo]);
                        $rows = $stmt->fetchAll();
                    } catch (\PDOException $e) {
                        error_log('[api][mysql][calendar] ' . $e->getMessage());
                        $rows = [];
                    }
                    $rows = map_fk_display($schema, $tableCfg, $rows);
                    foreach ($rows as $r) {
                        $events[] = [
                            'id' => $r[$idCol],
                            'table' => $table,
                            'title' => $r[$titleCol] ?? 'No title',
                            'date' => substr((string)($r[$dateCol] ?? ''), 0, 10),
                            'color' => $color,
                            'icon' => $src['icon'] ?? null,
                            'rowData' => $r
                        ];
                    }
                    continue;
                }

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
    if ($method === 'GET' && ($_GET['api'] ?? '') === 'board') {
        $boardPath = __DIR__ . '/../config/board.json';
        $boardCfg  = file_exists($boardPath)
            ? json_decode(file_get_contents($boardPath), true, 512, JSON_THROW_ON_ERROR)
            : [];

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
        // External MySQL tables don't exist in PostgreSQL — route board queries
        // through the MySQL gateway.
        $isMysqlBoard = in_array($table, mysql_gateway_tables(), true);
        $mysqlPkBoard = $tableCfg['mysql_pk'] ?? null;
        $realColBoard = fn(string $c): string => ($c === $idCol && $mysqlPkBoard !== null) ? $mysqlPkBoard : $c;
        if ($statusType === 'enum' && is_array($statusDef['options'] ?? null)) {
            foreach ($statusDef['options'] as $opt) {
                $val = (string)$opt;
                $lanes[] = [
                    'value' => $val,
                    'label' => $val,
                    'color' => $enumColors[$val] ?? $defaultColor,
                ];
            }
        } elseif ($isMysqlBoard) {
            $mysqlPdo = mysql_pdo('api');
            if ($mysqlPdo !== null) {
                $sqlDistinct = 'SELECT DISTINCT ' . mysql_bt($realColBoard($statusCol)) . ' AS v FROM '
                    . mysql_bt($table) . ' WHERE ' . mysql_bt($realColBoard($statusCol)) . ' IS NOT NULL ORDER BY 1';
                try {
                    $stmt = $mysqlPdo->query($sqlDistinct);
                    while ($r = $stmt->fetch()) {
                        $val = (string)$r['v'];
                        $lanes[] = ['value' => $val, 'label' => $val, 'color' => $defaultColor];
                    }
                } catch (\PDOException $e) {
                    error_log('[api][mysql][board] ' . $e->getMessage());
                }
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
        if ($isMysqlBoard) {
            $mysqlPdo = mysql_pdo('api');
            $rows     = [];
            if ($mysqlPdo !== null) {
                $mySelect = implode(', ', array_map(
                    fn($c) => ($c === $idCol && $mysqlPkBoard !== null)
                        ? mysql_bt($mysqlPkBoard) . ' AS ' . mysql_bt($idCol)
                        : mysql_bt($c),
                    $selectCols
                ));
                $sqlMy = 'SELECT ' . $mySelect . ' FROM ' . mysql_bt($table)
                    . ' ORDER BY ' . mysql_bt($realColBoard($idCol)) . ' DESC';
                try {
                    $stmt = $mysqlPdo->query($sqlMy);
                    $rows = $stmt->fetchAll();
                } catch (\PDOException $e) {
                    error_log('[api][mysql][board] ' . $e->getMessage());
                }
            }
            $rows = map_fk_display($schema, $tableCfg, $rows);
        } else {
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
        }
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
                'id'     => $r[$idCol],
                'status' => (string)($r[$statusCol] ?? ''),
                'title'  => $r[$titleCol . '__display'] ?? $r[$titleCol] ?? ('#' . $r[$idCol]),
                'fields' => $fields,
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
        $filterCol = $_GET['filter_col'] ?? '';
        $filterVal = $_GET['filter_val'] ?? '';
        $whereSql = '';
        $params = [];
        if ($filterCol !== '' && $filterVal !== '') {
            $allowedFilterCols = array_merge([$idCol], array_keys($tableCfg['columns'] ?? []));
            if (in_array($filterCol, $allowedFilterCols, true)) {
                $whereSql = sprintf(' WHERE %s = $1', pg_ident($filterCol));
                $params[] = $filterVal;
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

        // MySQL Gateway: serve this table from MySQL when it is in the routing list
        if (in_array($table, mysql_gateway_tables(), true)) {
            $mysqlPdo = mysql_pdo('api');
            if ($mysqlPdo === null) {
                http_response_code(503);
                echo json_encode(['error' => 'MySQL connection not configured or unavailable']);
                exit;
            }
            $myTable = mysql_bt($table);
            $mysqlPk = $tableCfg['mysql_pk'] ?? null;
            $realCol = fn(string $c): string => ($c === $idCol && $mysqlPk !== null) ? $mysqlPk : $c;
            $myCols  = array_map(
                fn($c) => ($c === $idCol && $mysqlPk !== null)
                    ? mysql_bt($mysqlPk) . ' AS ' . mysql_bt($idCol)
                    : mysql_bt($c),
                $selectCols
            );
            $mySelect = implode(', ', $myCols);
            $myParams = [];
            $myWhere  = '';
            if ($filterCol !== '' && $filterVal !== '') {
                $allowedFilterCols = array_merge([$idCol], array_keys($tableCfg['columns'] ?? []));
                if (in_array($filterCol, $allowedFilterCols, true)) {
                    $myWhere    = ' WHERE ' . mysql_bt($realCol($filterCol)) . ' = ?';
                    $myParams[] = $filterVal;
                }
            }
            if ($search !== '') {
                $likeVal = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $clauses = array_map(
                    fn($c) => 'CAST(' . mysql_bt($realCol($c)) . ' AS CHAR) LIKE ?',
                    $selectCols
                );
                $myWhere .= ($myWhere !== '' ? ' AND ' : ' WHERE ') . '(' . implode(' OR ', $clauses) . ')';
                foreach ($selectCols as $_ignored) {
                    $myParams[] = $likeVal;
                }
            }
            $myOrderClauses = [];
            if (is_array($defaultSort)) {
                foreach ($defaultSort as $rule) {
                    $col = $rule['column'] ?? '';
                    $dir = strtoupper($rule['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
                    if ($col !== '' && (isset($tableCfg['columns'][$col]) || $col === $idCol)) {
                        $myOrderClauses[] = mysql_bt($realCol($col)) . ' ' . $dir;
                    }
                }
            }
            if (empty($myOrderClauses)) {
                $myOrderClauses[] = mysql_bt($realCol($idCol)) . ' DESC';
            }
            $countStmt = $mysqlPdo->prepare('SELECT COUNT(*) FROM ' . $myTable . $myWhere);
            $countStmt->execute($myParams);
            $dbTotal  = (int) $countStmt->fetchColumn();
            $dataSql  = sprintf(
                'SELECT %s FROM %s%s ORDER BY %s LIMIT %d OFFSET %d',
                $mySelect,
                $myTable,
                $myWhere,
                implode(', ', $myOrderClauses),
                $rowCap,
                $offset
            );
            $dataStmt = $mysqlPdo->prepare($dataSql);
            $dataStmt->execute($myParams);
            $rows     = $dataStmt->fetchAll();
            echo json_encode([
                'columns'   => $selectCols,
                'rows'      => $rows,
                'truncated' => count($rows) === $rowCap,
                'total'     => $dbTotal,
                'table'     => [
                    'name'         => $table,
                    'display_name' => to_display_name($tableCfg),
                ],
            ]);
            exit;
        }

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
            $calPath = __DIR__ . '/../config/calendar.json';
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

            // External MySQL tables don't exist in PostgreSQL — route the update
            // through the MySQL gateway.
            if (in_array($table, mysql_gateway_tables(), true)) {
                $mysqlPdo = mysql_pdo('api');
                if ($mysqlPdo === null) {
                    http_response_code(503);
                    echo json_encode(['error' => 'MySQL connection not configured or unavailable']);
                    exit;
                }
                $myPkCol = $tableCfg['mysql_pk'] ?? $idCol;
                try {
                    $stmt = $mysqlPdo->prepare(
                        'UPDATE ' . mysql_bt($table) . ' SET ' . mysql_bt($dateColumn) . ' = ? WHERE ' . mysql_bt($myPkCol) . ' = ?'
                    );
                    $stmt->execute([$newDate, $id]);
                } catch (\PDOException $e) {
                    error_log('[api][mysql][calendar][move] ' . $e->getMessage());
                    http_response_code(500);
                    echo json_encode(['error' => 'Database error']);
                    exit;
                }
                // MySQL PDO rowCount() reports changed (not matched) rows, so a
                // drop onto the same date returns 0 — treat a clean execute as
                // success rather than a spurious 404.
                log_user_action($conn, (int)$_SESSION['user_id'], 'CALENDAR_MOVE', $table, $id);
                echo json_encode(['success' => true]);
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

            $boardPath = __DIR__ . '/../config/board.json';
            $boardCfg  = file_exists($boardPath) ? json_decode(file_get_contents($boardPath), true) : [];
            $cfgTable  = $boardCfg['table'] ?? '';
            $statusCol = $boardCfg['status_column'] ?? '';

            // The board is bound to a single configured table — reject anything else.
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
            $isMysqlMove = in_array($table, mysql_gateway_tables(), true);
            $mysqlPkMove = $tableCfg['mysql_pk'] ?? $idCol;
            $allowed    = [];
            if ($statusType === 'enum' && is_array($statusDef['options'] ?? null)) {
                $allowed = array_map('strval', $statusDef['options']);
            } elseif ($isMysqlMove) {
                $mysqlPdo = mysql_pdo('api');
                if ($mysqlPdo !== null) {
                    try {
                        $stmtD = $mysqlPdo->query(
                            'SELECT DISTINCT ' . mysql_bt($statusCol) . ' AS v FROM '
                            . mysql_bt($table) . ' WHERE ' . mysql_bt($statusCol) . ' IS NOT NULL'
                        );
                        while ($r = $stmtD->fetch()) {
                            $allowed[] = (string)$r['v'];
                        }
                    } catch (\PDOException $e) {
                        error_log('[api][mysql][board][move] ' . $e->getMessage());
                    }
                }
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

            // External MySQL tables don't exist in PostgreSQL — route the update
            // through the MySQL gateway.
            if ($isMysqlMove) {
                $mysqlPdo = mysql_pdo('api');
                if ($mysqlPdo === null) {
                    http_response_code(503);
                    echo json_encode(['error' => 'MySQL connection not configured or unavailable']);
                    exit;
                }
                try {
                    $stmt = $mysqlPdo->prepare(
                        'UPDATE ' . mysql_bt($table) . ' SET ' . mysql_bt($statusCol) . ' = ? WHERE ' . mysql_bt($mysqlPkMove) . ' = ?'
                    );
                    $stmt->execute([$newStatus, $id]);
                } catch (\PDOException $e) {
                    error_log('[api][mysql][board][move] ' . $e->getMessage());
                    http_response_code(500);
                    echo json_encode(['error' => 'Database error']);
                    exit;
                }
                // rowCount() reports changed rows in MySQL — a no-op drop returns 0,
                // so a clean execute is treated as success rather than a 404.
                log_user_action($conn, (int)$_SESSION['user_id'], 'BOARD_MOVE', $table, $id);
                echo json_encode(['success' => true]);
                exit;
            }

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

            // MySQL Gateway — UPDATE single cell
            if (in_array($table, mysql_gateway_tables(), true)) {
                $mysqlPdo = mysql_pdo('api');
                if ($mysqlPdo === null) {
                    http_response_code(503);
                    echo json_encode(['error' => 'MySQL not configured or unavailable']);
                    exit;
                }
                $myVal = str_contains($colType, 'bool')
                    ? (($val === null) ? null : (in_array($val, ['TRUE', 'true', '1', 't', 'T'], true) ? 1 : 0))
                    : $val;
                $myPkCol = $tableCfg['mysql_pk'] ?? $idCol;
                try {
                    $stmt = $mysqlPdo->prepare(
                        'UPDATE ' . mysql_bt($table) . ' SET ' . mysql_bt($col) . ' = ? WHERE ' . mysql_bt($myPkCol) . ' = ?'
                    );
                    $stmt->execute([$myVal, $recordId]);
                } catch (\PDOException $e) {
                    error_log('[api][mysql][patch] ' . $e->getMessage());
                    http_response_code(422);
                    echo json_encode(['error' => 'Database error']);
                    exit;
                }
                log_user_action($conn, (int)$_SESSION['user_id'], 'UPDATE', $table, (int)$body['id']);
                echo json_encode(['ok' => true]);
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

            // MySQL Gateway — INSERT new row
            if (in_array($table, mysql_gateway_tables(), true)) {
                $mysqlPdo = mysql_pdo('api');
                if ($mysqlPdo === null) {
                    http_response_code(503);
                    echo json_encode(['error' => 'MySQL not configured or unavailable']);
                    exit;
                }
                $myVals = [];
                foreach ($cols as $ci => $cn) {
                    $ct       = strtolower($tableCfg['columns'][$cn]['type'] ?? '');
                    $v        = $vals[$ci];
                    $myVals[] = str_contains($ct, 'bool')
                        ? (($v === null) ? null : (in_array($v, ['TRUE', 'true', '1', 't', 'T'], true) ? 1 : 0))
                        : $v;
                }
                try {
                    if (empty($cols)) {
                        $stmt = $mysqlPdo->prepare('INSERT INTO ' . mysql_bt($table) . ' () VALUES ()');
                        $stmt->execute([]);
                    } else {
                        $myCols = implode(', ', array_map('mysql_bt', $cols));
                        $myPh   = implode(', ', array_fill(0, count($myVals), '?'));
                        $stmt   = $mysqlPdo->prepare(
                            'INSERT INTO ' . mysql_bt($table) . ' (' . $myCols . ') VALUES (' . $myPh . ')'
                        );
                        $stmt->execute($myVals);
                    }
                    $newId = (string) $mysqlPdo->lastInsertId();
                } catch (\PDOException $e) {
                    error_log('[api][mysql][insert] ' . $e->getMessage());
                    http_response_code(422);
                    echo json_encode(['error' => 'Database error']);
                    exit;
                }
                $userId = (int)$_SESSION['user_id'];
                log_user_action($conn, $userId, 'INSERT', $table, (int) $newId);
                echo json_encode(['ok' => true, 'id' => $newId]);
                exit;
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

            // MySQL Gateway — DELETE row
            if (in_array($table, mysql_gateway_tables(), true)) {
                $mysqlPdo = mysql_pdo('api');
                if ($mysqlPdo === null) {
                    http_response_code(503);
                    echo json_encode(['error' => 'MySQL not configured or unavailable']);
                    exit;
                }
                $myPkCol = $tableCfg['mysql_pk'] ?? $idCol;
                try {
                    $stmt = $mysqlPdo->prepare(
                        'DELETE FROM ' . mysql_bt($table) . ' WHERE ' . mysql_bt($myPkCol) . ' = ?'
                    );
                    $stmt->execute([$deleteId]);
                } catch (\PDOException $e) {
                    error_log('[api][mysql][delete] ' . $e->getMessage());
                    http_response_code(422);
                    echo json_encode(['error' => 'Database error']);
                    exit;
                }
                log_user_action($conn, (int)$_SESSION['user_id'], 'DELETE', $table, $deleteId);
                echo json_encode(['ok' => true]);
                exit;
            }

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
