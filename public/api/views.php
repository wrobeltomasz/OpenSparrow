<?php

declare(strict_types=1);

// api/views.php — Saved/custom views API (backed by PostgreSQL views)
// Auth gate: session + UA enforcement; CSRF on POST
// actions: list (GET), config (GET, admin), data (GET — runs the view SELECT / drill-down GROUP BY),
// schemas (GET, admin — lists PostgreSQL schemas + the configured search selection),
// sync (GET, admin — discovers views via information_schema.VIEWS, scoped to
// the "views" config "schemas" key for postgres), save (POST, admin)
// Reads/writes the "views" config; column names validated against schema, values parameterized

require_once __DIR__ . '/../../includes/bootstrap.php';

// Auth gate + header CSRF on POST; connect=false — actions open their own connection
os_api_bootstrap(['connect' => false]);

$role   = $_SESSION['role'] ?? 'viewer';
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

require_once __DIR__ . '/../../includes/config_store.php';
$viewsConfig = config_get('views') ?? [];
$views       = $viewsConfig['views'] ?? [];

try {
    /* LIST — visible views for FE menu/selector */
    if ($action === 'list' && $method === 'GET') {
        $result = [];
        foreach ($views as $name => $cfg) {
            if (!empty($cfg['hidden'])) {
                continue;
            }
            $result[] = [
                'name'         => $name,
                'display_name' => $cfg['display_name'] ?? $name,
                'description'  => $cfg['description'] ?? '',
                'icon'         => $cfg['icon'] ?? '',
                'menu_name'    => $cfg['menu_name'] ?? ($cfg['display_name'] ?? $name),
            ];
        }
        echo json_encode(['status' => 'ok', 'views' => $result]);
        exit;
    }

    /* CONFIG — full config for admin editor */
    if ($action === 'config' && $method === 'GET' && $role === 'admin') {
        echo json_encode(['status' => 'ok', 'config' => $viewsConfig]);
        exit;
    }

    /* DATA — query view data with optional drill-down */
    if ($action === 'data' && $method === 'GET') {
        $viewName = $_GET['view'] ?? '';
        if (!isset($views[$viewName])) {
            http_response_code(404);
            echo json_encode(['error' => 'View not found']);
            exit;
        }

        $cfg        = $views[$viewName];
        $conn       = db_connect();
        $schemaName = $cfg['schema'] ?? sys_schema();
        $level      = max(0, (int)($_GET['level'] ?? 0));
        $filterCol  = $_GET['filter_col'] ?? '';
        $filterVal  = isset($_GET['filter_val']) ? $_GET['filter_val'] : null;

        $drillLevels = $cfg['drill_down']['levels'] ?? [];
        $groupBy     = null;
        if (!empty($drillLevels) && isset($drillLevels[$level])) {
            $groupBy = $drillLevels[$level]['group_by'] ?? null;
        }

        $params      = [];
        $whereClause = '';

        if ($filterCol !== '' && $filterVal !== null) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $filterCol)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid filter column']);
                exit;
            }
            $params[]    = $filterVal;
            $whereClause = 'WHERE ' . pg_ident($filterCol) . ' = $1';
        }

        if ($groupBy !== null) {
            $colsCfg  = $cfg['columns'] ?? [];
            $aggParts = [];
            foreach ($colsCfg as $colName => $colCfg) {
                if ($colName === $groupBy) {
                    continue;
                }
                $agg = strtolower($colCfg['aggregate'] ?? '');
                if ($agg === 'count') {
                    $aggParts[] = 'COUNT(*) AS ' . pg_ident($colName);
                } elseif ($agg === 'sum') {
                    $aggParts[] = 'SUM(' . pg_ident($colName) . ') AS ' . pg_ident($colName);
                } elseif ($agg === 'avg') {
                    $aggParts[] = 'ROUND(AVG(' . pg_ident($colName) . ')::numeric, 2) AS ' . pg_ident($colName);
                }
            }

            $selectExtra = empty($aggParts) ? 'COUNT(*) AS _count' : implode(', ', $aggParts);
            $sql         = sprintf(
                'SELECT %s, %s FROM %s.%s %s GROUP BY %s ORDER BY 2 DESC LIMIT 1000',
                pg_ident($groupBy),
                $selectExtra,
                pg_ident($schemaName),
                pg_ident($viewName),
                $whereClause,
                pg_ident($groupBy)
            );
        } else {
            $sql = sprintf(
                'SELECT * FROM %s.%s %s LIMIT 1000',
                pg_ident($schemaName),
                pg_ident($viewName),
                $whereClause
            );
        }

        $res = @pg_query_params($conn, $sql, $params);
        if (!$res) {
            error_log('[api_views][data] ' . pg_last_error($conn));
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
            exit;
        }

        $rows = pg_fetch_all($res) ?: [];
        pg_free_result($res);

        echo json_encode([
            'status'       => 'ok',
            'view'         => $viewName,
            'display_name' => $cfg['display_name'] ?? $viewName,
            'level'        => $level,
            'max_level'    => max(0, count($drillLevels) - 1),
            'group_by'     => $groupBy,
            'drill_enabled' => !empty($cfg['drill_down']['enabled']),
            'rows'         => $rows,
            'columns'      => $cfg['columns'] ?? [],
            'drill_down'   => $cfg['drill_down'] ?? ['enabled' => false, 'levels' => []],
            'group_rows'   => $cfg['group_rows'] ?? '',
            'icon'         => $cfg['icon'] ?? '',
        ]);
        exit;
    }

    /* SCHEMAS — list PostgreSQL schemas + the currently configured search selection (admin only) */
    if ($action === 'schemas' && $method === 'GET' && $role === 'admin') {
        $conn = db_connect();
        $sql  = 'SELECT schema_name FROM information_schema.schemata '
            . "WHERE schema_name NOT IN ('pg_catalog', 'information_schema') "
            . "AND schema_name NOT LIKE 'pg\\_toast%' AND schema_name NOT LIKE 'pg\\_temp%' "
            . 'ORDER BY schema_name';
        $res = @pg_query($conn, $sql);
        if (!$res) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
            exit;
        }

        $schemas = [];
        while ($row = pg_fetch_assoc($res)) {
            $schemas[] = $row['schema_name'];
        }
        pg_free_result($res);

        $selected = is_array($viewsConfig['schemas'] ?? null) ? $viewsConfig['schemas'] : [];
        if (empty($selected)) {
            $selected = [sys_schema()];
        }

        echo json_encode(['status' => 'ok', 'schemas' => $schemas, 'selected' => $selected]);
        exit;
    }

    /* SYNC — read DB views list and column metadata (admin only) */
    if ($action === 'sync' && $method === 'GET' && $role === 'admin') {
        $conn    = db_connect();
        $schemas = is_array($viewsConfig['schemas'] ?? null) ? $viewsConfig['schemas'] : [];
        $schemas = array_values(array_filter(array_map('strval', $schemas), fn($s) => $s !== ''));
        if (empty($schemas)) {
            $schemas = [sys_schema()];
        }

        $placeholders = implode(',', array_map(fn($i) => '$' . ($i + 1), array_keys($schemas)));
        $sql          = "SELECT table_schema, table_name FROM information_schema.views "
            . "WHERE table_schema IN ($placeholders) ORDER BY table_schema, table_name";
        $res = @pg_query_params($conn, $sql, $schemas);
        if (!$res) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
            exit;
        }

        $dbViews     = [];
        $viewSchemas = [];
        while ($row = pg_fetch_assoc($res)) {
            $dbViews[]                       = $row['table_name'];
            $viewSchemas[$row['table_name']] = $row['table_schema'];
        }
        pg_free_result($res);

        $viewsColumns = [];
        foreach ($dbViews as $vName) {
            $colSql = 'SELECT column_name, data_type FROM information_schema.columns '
                . 'WHERE table_schema = $1 AND table_name = $2 ORDER BY ordinal_position';
            $colRes = @pg_query_params($conn, $colSql, [$viewSchemas[$vName], $vName]);
            $cols   = [];
            if ($colRes) {
                while ($col = pg_fetch_assoc($colRes)) {
                    $cols[$col['column_name']] = ['data_type' => $col['data_type']];
                }
                pg_free_result($colRes);
            }
            $viewsColumns[$vName] = $cols;
        }

        echo json_encode([
            'status'       => 'ok',
            'db_views'     => $dbViews,
            'columns'      => $viewsColumns,
            'view_schemas' => $viewSchemas,
            'source'       => 'postgres',
        ]);
        exit;
    }

    /* SAVE CONFIG — persist to the spw_config store, key "views" (admin only) */
    if ($action === 'save' && $method === 'POST' && $role === 'admin') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['views'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            exit;
        }

        // Preserve the top-level "schemas" selection (multi-schema sync scope) —
        // this action only replaces the views map.
        $newConfig = ['views' => $body['views']];
        if (is_array($viewsConfig['schemas'] ?? null)) {
            $newConfig['schemas'] = $viewsConfig['schemas'];
        }

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $result = config_save('views', $newConfig, null, $userId);
        if ($result['status'] !== 'ok') {
            $tooLarge = ($result['error'] ?? '') === 'Config too large';
            http_response_code($tooLarge ? 413 : 500);
            echo json_encode(['error' => $result['error'] ?? 'Write failed']);
            exit;
        }

        echo json_encode(['status' => 'ok']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or insufficient permissions']);
} catch (Throwable $e) {
    error_log('[api_views][exception] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
