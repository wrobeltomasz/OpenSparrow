<?php

declare(strict_types=1);

// api/print.php — Print templates API (print.php page + admin Printouts editor)
// Auth gate: session + UA enforcement; CSRF on POST
// actions: list (GET), config (GET, admin), columns (GET, admin — live column list of a
// PostgreSQL view), data (GET — template blocks + view rows), save (POST, admin)
// Templates live in config/print.json (web-denied via config/.htaccess); each template is bound
// to a PostgreSQL view registered in config/views.json — never to raw SQL from the client.

require_once __DIR__ . '/../../includes/bootstrap.php';

// Auth gate + header CSRF on POST; connect=false — actions open their own connection
os_api_bootstrap(['connect' => false]);

$role   = $_SESSION['role'] ?? 'viewer';
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$printPath   = __DIR__ . '/../../config/print.json';
$printConfig = [];
if (file_exists($printPath)) {
    $raw     = file_get_contents($printPath);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $printConfig = $decoded;
    }
}
$prints = $printConfig['prints'] ?? [];

/**
 * PostgreSQL-sourced views registered in config/views.json — the only allowed
 * data sources for print templates. Returns name => view config.
 */
function print_available_views(): array
{
    $viewsPath = __DIR__ . '/../../config/views.json';
    if (!file_exists($viewsPath)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($viewsPath), true);
    $out     = [];
    foreach (($decoded['views'] ?? []) as $name => $cfg) {
        if (!is_array($cfg) || ($cfg['source'] ?? 'postgres') !== 'postgres') {
            continue;
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $name)) {
            continue;
        }
        $out[(string) $name] = $cfg;
    }
    return $out;
}

/**
 * Whitelist-validate one template payload from the admin editor.
 * Returns the sanitized template, or null when structurally invalid.
 */
function print_sanitize_template(array $tpl, array $availableViews): ?array
{
    $view = (string) ($tpl['view'] ?? '');
    if ($view !== '' && !isset($availableViews[$view])) {
        return null;
    }

    $icon = (string) ($tpl['icon'] ?? '');
    if (
        $icon !== ''
        && (str_contains($icon, '..') || !preg_match('#^assets/[a-z0-9_\-/.]+\.(png|svg|gif|jpe?g|webp)$#i', $icon))
    ) {
        $icon = '';
    }

    $blocks = [];
    foreach (array_slice((array) ($tpl['blocks'] ?? []), 0, 50) as $block) {
        if (!is_array($block)) {
            return null;
        }
        $type = $block['type'] ?? '';
        if ($type === 'header') {
            $level    = (int) ($block['level'] ?? 1);
            $blocks[] = [
                'type'  => 'header',
                'text'  => mb_substr((string) ($block['text'] ?? ''), 0, 500),
                'level' => max(1, min(3, $level)),
            ];
        } elseif ($type === 'text') {
            $blocks[] = [
                'type' => 'text',
                'text' => mb_substr((string) ($block['text'] ?? ''), 0, 5000),
            ];
        } elseif ($type === 'table') {
            $cols = [];
            foreach (array_slice((array) ($block['columns'] ?? []), 0, 50) as $col) {
                // Accepts a legacy bare column-name string, or {name, width?, align?}.
                $name = is_string($col) ? $col : (string) ($col['name'] ?? '');
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_ ]*$/', $name)) {
                    continue;
                }
                $entry = ['name' => $name, 'align' => 'left'];
                if (is_array($col)) {
                    if (in_array($col['align'] ?? '', ['left', 'center', 'right'], true)) {
                        $entry['align'] = $col['align'];
                    }
                    if (isset($col['width']) && is_numeric($col['width'])) {
                        $width = (int) $col['width'];
                        if ($width >= 1 && $width <= 100) {
                            $entry['width'] = $width;
                        }
                    }
                }
                $cols[] = $entry;
            }
            $blocks[] = ['type' => 'table', 'columns' => $cols];
        } else {
            return null;
        }
    }

    return [
        'display_name' => mb_substr((string) ($tpl['display_name'] ?? ''), 0, 120),
        'menu_name'    => mb_substr((string) ($tpl['menu_name'] ?? ''), 0, 120),
        'description'  => mb_substr((string) ($tpl['description'] ?? ''), 0, 500),
        'icon'         => $icon,
        'hidden'       => !empty($tpl['hidden']),
        'view'         => $view,
        'blocks'       => $blocks,
    ];
}

try {
    /* LIST — visible print templates for FE menu/selector */
    if ($action === 'list' && $method === 'GET') {
        $result = [];
        foreach ($prints as $name => $cfg) {
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
        echo json_encode(['status' => 'ok', 'prints' => $result]);
        exit;
    }

    /* CONFIG — full config + selectable PostgreSQL views for the admin editor */
    if ($action === 'config' && $method === 'GET' && $role === 'admin') {
        echo json_encode([
            'status' => 'ok',
            // (object) keeps an empty map as {} in JSON — [] would become a JS array
            'config' => ['prints' => (object) $prints],
            'views'  => array_keys(print_available_views()),
        ]);
        exit;
    }

    /* COLUMNS — live column list of one registered PostgreSQL view (admin editor variables) */
    if ($action === 'columns' && $method === 'GET' && $role === 'admin') {
        $viewName = $_GET['view'] ?? '';
        $views    = print_available_views();
        if (!isset($views[$viewName])) {
            http_response_code(404);
            echo json_encode(['error' => 'View not found']);
            exit;
        }

        $conn       = db_connect();
        $schemaName = $views[$viewName]['schema'] ?? sys_schema();
        $sql        = 'SELECT column_name, data_type FROM information_schema.columns '
            . 'WHERE table_schema = $1 AND table_name = $2 ORDER BY ordinal_position';
        $res        = @pg_query_params($conn, $sql, [$schemaName, $viewName]);
        if (!$res) {
            error_log('[api_print][columns] ' . pg_last_error($conn));
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
            exit;
        }

        $cols = [];
        while ($col = pg_fetch_assoc($res)) {
            $cols[] = ['name' => $col['column_name'], 'data_type' => $col['data_type']];
        }
        pg_free_result($res);

        echo json_encode(['status' => 'ok', 'view' => $viewName, 'columns' => $cols]);
        exit;
    }

    /* DATA — template blocks + rows of the bound view for the print page */
    if ($action === 'data' && $method === 'GET') {
        $printName = $_GET['print'] ?? '';
        if (!isset($prints[$printName])) {
            http_response_code(404);
            echo json_encode(['error' => 'Print template not found']);
            exit;
        }

        $cfg      = $prints[$printName];
        $views    = print_available_views();
        $viewName = (string) ($cfg['view'] ?? '');
        $rows     = [];
        $viewCols = [];

        if ($viewName !== '' && isset($views[$viewName])) {
            $conn       = db_connect();
            $schemaName = $views[$viewName]['schema'] ?? sys_schema();
            $sql        = sprintf(
                'SELECT * FROM %s.%s LIMIT 1000',
                pg_ident($schemaName),
                pg_ident($viewName)
            );
            $res        = @pg_query_params($conn, $sql, []);
            if (!$res) {
                error_log('[api_print][data] ' . pg_last_error($conn));
                http_response_code(500);
                echo json_encode(['error' => 'Database error']);
                exit;
            }
            $rows = pg_fetch_all($res) ?: [];
            pg_free_result($res);
            $viewCols = $views[$viewName]['columns'] ?? [];
        }

        echo json_encode([
            'status'       => 'ok',
            'print'        => $printName,
            'display_name' => $cfg['display_name'] ?? $printName,
            'icon'         => $cfg['icon'] ?? '',
            'view'         => $viewName,
            'blocks'       => $cfg['blocks'] ?? [],
            'rows'         => $rows,
            'columns'      => $viewCols,
        ]);
        exit;
    }

    /* SAVE CONFIG — persist config/print.json (admin only) */
    if ($action === 'save' && $method === 'POST' && $role === 'admin') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['prints']) || !is_array($body['prints'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            exit;
        }

        $views     = print_available_views();
        $sanitized = [];
        foreach ($body['prints'] as $name => $tpl) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name) || !is_array($tpl)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid template key: ' . mb_substr((string) $name, 0, 64)]);
                exit;
            }
            $clean = print_sanitize_template($tpl, $views);
            if ($clean === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid template: ' . $name]);
                exit;
            }
            $sanitized[$name] = $clean;
        }

        $json = json_encode(['prints' => (object) $sanitized], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > CONFIG_FILE_MAX_BYTES) {
            http_response_code(413);
            echo json_encode(['error' => 'Config too large']);
            exit;
        }

        $tmp = $printPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Write failed']);
            exit;
        }
        rename($tmp, $printPath);

        echo json_encode(['status' => 'ok']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or insufficient permissions']);
} catch (Throwable $e) {
    error_log('[api_print][exception] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
