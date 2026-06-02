<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
start_session();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/api_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$role   = $_SESSION['role'] ?? 'viewer';
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }
}

$viewsPath   = __DIR__ . '/config/views.json';
$viewsConfig = [];
if (file_exists($viewsPath)) {
    $raw     = file_get_contents($viewsPath);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $viewsConfig = $decoded;
    }
}
$views = $viewsConfig['views'] ?? [];

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
            'icon'         => $cfg['icon'] ?? '',
        ]);
        exit;
    }

    /* SYNC — read DB views list and column metadata (admin only) */
    if ($action === 'sync' && $method === 'GET' && $role === 'admin') {
        $conn       = db_connect();
        $schemaName = sys_schema();

        $sql = 'SELECT table_name FROM information_schema.views WHERE table_schema = $1 ORDER BY table_name';
        $res = @pg_query_params($conn, $sql, [$schemaName]);
        if (!$res) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
            exit;
        }

        $dbViews = [];
        while ($row = pg_fetch_assoc($res)) {
            $dbViews[] = $row['table_name'];
        }
        pg_free_result($res);

        $viewsColumns = [];
        foreach ($dbViews as $vName) {
            $colSql = 'SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = $1 AND table_name = $2 ORDER BY ordinal_position';
            $colRes = @pg_query_params($conn, $colSql, [$schemaName, $vName]);
            $cols   = [];
            if ($colRes) {
                while ($col = pg_fetch_assoc($colRes)) {
                    $cols[$col['column_name']] = ['data_type' => $col['data_type']];
                }
                pg_free_result($colRes);
            }
            $viewsColumns[$vName] = $cols;
        }

        echo json_encode(['status' => 'ok', 'db_views' => $dbViews, 'columns' => $viewsColumns]);
        exit;
    }

    /* SAVE CONFIG — persist views.json (admin only) */
    if ($action === 'save' && $method === 'POST' && $role === 'admin') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['views'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            exit;
        }

        $newConfig = ['views' => $body['views']];
        $json      = json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (strlen($json) > CONFIG_FILE_MAX_BYTES) {
            http_response_code(413);
            echo json_encode(['error' => 'Config too large']);
            exit;
        }

        $tmp = $viewsPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Write failed']);
            exit;
        }
        rename($tmp, $viewsPath);

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
