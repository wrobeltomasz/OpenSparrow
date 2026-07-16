<?php

declare(strict_types=1);

// includes/admin/config_files.php — admin api.php module: menu_config GET/POST + whitelisted config/*.json editor
// (get, save).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// Shared helpers for menu_config GET and POST
$menuMaxBytes = CONFIG_FILE_MAX_BYTES;
$menuSafeReadJson = static function (string $path) use ($menuMaxBytes): ?array {
    if (!file_exists($path) || filesize($path) > $menuMaxBytes) {
        return null;
    }
    $content = file_get_contents($path, false, null, 0, $menuMaxBytes);
    if ($content === false) {
        return null;
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
};
$menuSanitizeIcon = static function (string $icon): string {
    if ($icon === '') {
        return '';
    }
    // Local assets/ paths only — mirrors renderMenuIcon() in templates/menu.php
    // (offline policy: no external URLs; no path traversal).
    if (
        !str_contains($icon, '..')
        && preg_match('#^assets/[a-z0-9_\-/.]+\.(png|svg|gif|jpe?g|webp)$#i', $icon)
    ) {
        return $icon;
    }
    return '';
};

// GET: return structured (possibly nested) menu item list for the admin Menu Preview tab
if ($action === 'menu_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');

    $inc = __DIR__ . '/../../config';

    // Build catalog: key → full display entry
    $catalog = [];

    require_once __DIR__ . '/../config_store.php';
    $dashRaw = config_get('dashboard') ?? [];
    $catalog['dashboard'] = [
        'type' => 'dashboard', 'key' => 'dashboard',
        'name'   => $dashRaw['menu_name'] ?? 'Dashboard',
        'icon'   => $menuSanitizeIcon((string)($dashRaw['menu_icon'] ?? 'assets/icons/dashboard.png')),
        'hidden' => !empty($dashRaw['hidden']),
        'children' => [],
    ];

    $calRaw = config_get('calendar') ?? [];
    $catalog['calendar'] = [
        'type' => 'calendar', 'key' => 'calendar',
        'name'   => $calRaw['menu_name'] ?? 'Calendar',
        'icon'   => $menuSanitizeIcon((string)($calRaw['menu_icon'] ?? 'assets/icons/calendar.png')),
        'hidden' => !empty($calRaw['hidden']),
        'children' => [],
    ];

    $boardRaw = config_get('board') ?? [];
    if (!empty($boardRaw['table']) && !empty($boardRaw['status_column'])) {
        $catalog['board'] = [
            'type' => 'board', 'key' => 'board',
            'name'   => $boardRaw['menu_name'] ?? 'Board',
            'icon'   => $menuSanitizeIcon((string)($boardRaw['menu_icon'] ?? 'assets/icons/account_tree.png')),
            'hidden' => !empty($boardRaw['hidden']),
            'children' => [],
        ];
    }

    $filesRaw = config_get('files') ?? [];
    $catalog['files'] = [
        'type' => 'files', 'key' => 'files',
        'name'   => $filesRaw['menu_name'] ?? 'Files',
        'icon'   => $menuSanitizeIcon((string)($filesRaw['menu_icon'] ?? 'assets/icons/folder_open.png')),
        'hidden' => !empty($filesRaw['hidden']),
        'children' => [],
    ];

    $schemaRaw = config_get('schema') ?? [];
    foreach ($schemaRaw['tables'] ?? [] as $tName => $tConfig) {
        $catalog[$tName] = [
            'type' => 'table', 'key' => $tName,
            'name'   => $tConfig['display_name'] ?? $tName,
            'icon'   => $menuSanitizeIcon((string)($tConfig['icon'] ?? '')),
            'hidden' => !empty($tConfig['hidden']),
            'children' => [],
        ];
    }

    $menuRaw = config_get('menu');
    $items   = [];
    $placed  = [];

    if ($menuRaw !== null && isset($menuRaw['items']) && is_array($menuRaw['items'])) {
        foreach ($menuRaw['items'] as $entry) {
            $key = $entry['key'] ?? '';
            if ($key === '' || !isset($catalog[$key])) {
                continue;
            }
            $item = $catalog[$key];
            $item['children'] = [];
            foreach ($entry['children'] ?? [] as $ce) {
                $ck = $ce['key'] ?? '';
                if ($ck === '' || !isset($catalog[$ck])) {
                    continue;
                }
                $child = $catalog[$ck];
                $child['children'] = [];
                $item['children'][] = $child;
                $placed[$ck] = true;
            }
            $items[]      = $item;
            $placed[$key] = true;
        }
        // Append items added after menu.json was last saved
        foreach ($catalog as $key => $entry) {
            if (!isset($placed[$key])) {
                $items[] = $entry;
            }
        }
    } else {
        $items = array_values($catalog);
    }

    echo json_encode(['items' => $items]);
    exit;
}

// POST: save menu structure (order + nesting) to config/menu.json
if ($action === 'menu_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Invalid payload']);
        exit;
    }

    require_once __DIR__ . '/../config_store.php';
    $schemaRaw = config_get('schema') ?? [];
    $validKeys  = array_merge(['dashboard', 'calendar', 'board', 'files'], array_keys($schemaRaw['tables'] ?? []));
    $validTypes = ['dashboard', 'calendar', 'board', 'files', 'table'];

    $sanitized = [];
    foreach ($body['items'] as $entry) {
        $key  = $entry['key']  ?? '';
        $type = $entry['type'] ?? '';
        if (!in_array($key, $validKeys, true) || !in_array($type, $validTypes, true)) {
            continue;
        }
        $children = [];
        foreach ($entry['children'] ?? [] as $child) {
            $ck = $child['key']  ?? '';
            $ct = $child['type'] ?? '';
            if (!in_array($ck, $validKeys, true) || !in_array($ct, $validTypes, true)) {
                continue;
            }
            // 'type' is only used above to validate the incoming item — the renderer
            // (templates/menu.php) looks up the real type from $menuCatalog by key, so
            // it is intentionally not persisted here.
            $children[] = ['key' => $ck, 'children' => []];
        }
        $sanitized[] = ['key' => $key, 'children' => $children];
    }

    $menuUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $menuResult = config_save('menu', ['items' => $sanitized], null, $menuUserId);
    if ($menuResult['status'] !== 'ok') {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => $menuResult['error'] ?? 'Write failed']);
        exit;
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// Allowed config files for read and write operations
// Dodałem 'files' do autoryzowanych konfiguracji
$allowedFiles = [
    'schema', 'dashboard', 'calendar', 'board', 'database', 'security',
    'workflows', 'files', 'views', 'automations', 'user_records',
];

// Keys served from the spw_config store instead of config/*.json (see
// includes/config_store.php — reads still fall back to the legacy file until
// the one-time init_db import has run). Extend as modules migrate.
$dbBackedFiles = ['automations', 'board', 'calendar', 'dashboard', 'files', 'schema', 'user_records', 'views', 'workflows'];

// Get content of a JSON config file
if ($action === 'get' && in_array($file, $allowedFiles, true)) {
    header('Content-Type: application/json');
    if (in_array($file, $dbBackedFiles, true)) {
        require_once __DIR__ . '/../config_store.php';
        $cfg = config_get($file);
        echo $cfg !== null ? json_encode($cfg) : json_encode(new stdClass());
        exit;
    }
    $filePath = __DIR__ . '/../../config/' . $file . '.json';
    if (file_exists($filePath)) {
        $fileContent = file_get_contents($filePath);
        // Mask sensitive data in Demo Mode
        if ($isDemoMode && $file === 'database') {
            $dbData = json_decode($fileContent, true);
            $dbData['host'] = 'hidden-for-demo.postgres.database.azure.com';
            $dbData['user'] = 'demo_user_hidden';
            $dbData['password'] = '********';
            $dbData['dbname'] = 'demo_db';
            echo json_encode($dbData);
        } else {
            echo $fileContent;
        }
    } else {
        echo json_encode(new stdClass());
    }
    exit;
}

// Save content to a JSON config file
if ($action === 'save' && in_array($file, $allowedFiles, true)) {
    // Block saving sensitive files in Demo Mode
    if ($isDemoMode && in_array($file, ['database', 'security'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'Saving ' . $file . ' configuration is disabled in Demo Mode.']);
        exit;
    }

    $data = file_get_contents('php://input');
    $filePath = __DIR__ . '/../../config/' . $file . '.json';
    header('Content-Type: application/json');
    $parsedData = json_decode($data, true);
    if (in_array($file, $dbBackedFiles, true)) {
        if (!is_array($parsedData)) {
            echo json_encode(['status' => 'error', 'error' => 'Invalid JSON']);
            exit;
        }
        require_once __DIR__ . '/../config_store.php';
        // Generic editor has no version plumbing — last-write-wins (expected null).
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $result = config_save($file, $parsedData, null, $userId);
        if ($result['status'] !== 'ok') {
            echo json_encode(['status' => 'error', 'error' => $result['error'] ?? 'Save failed']);
            exit;
        }
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($parsedData !== null) {
        if (!is_dir(__DIR__ . '/../../config/')) {
            mkdir(__DIR__ . '/../../config/', 0755, true);
        }
        file_put_contents($filePath, json_encode($parsedData, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'error' => 'Invalid JSON']);
    }
    exit;
}
