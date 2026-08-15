<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ResponseException;

$menuSanitizeIcon = static function (string $icon): string {
    if ($icon === '') {
        return '';
    }

    if (
        !str_contains($icon, '..')
        && preg_match('#^assets/[a-z0-9_\-/.]+\.(png|svg|gif|jpe?g|webp)$#i', $icon)
    ) {
        return $icon;
    }
    return '';
};

if ($action === 'menu_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
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

    $filesRaw = config_get('files') ?? [];
    $catalog['files'] = [
        'type' => 'files', 'key' => 'files',
        'name'   => $filesRaw['menu_name'] ?? 'Files',
        'icon'   => $menuSanitizeIcon((string)($filesRaw['menu_icon'] ?? 'assets/icons/folder_open.png')),
        'hidden' => !empty($filesRaw['hidden']),
        'children' => [],
    ];

    $schemaRaw = config_get('schema') ?? [];
    foreach ($schemaRaw['tables'] ?? [] as $tableName => $tableConfig) {
        $catalog[$tableName] = [
            'type' => 'table', 'key' => $tableName,
            'name'   => $tableConfig['display_name'] ?? $tableName,
            'icon'   => $menuSanitizeIcon((string)($tableConfig['icon'] ?? '')),
            'hidden' => !empty($tableConfig['hidden']),
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
            foreach ($entry['children'] ?? [] as $configEntry) {
                $configKey = $configEntry['key'] ?? '';
                if ($configKey === '' || !isset($catalog[$configKey])) {
                    continue;
                }
                $child = $catalog[$configKey];
                $child['children'] = [];
                $item['children'][] = $child;
                $placed[$configKey] = true;
            }
            $items[]      = $item;
            $placed[$key] = true;
        }

        foreach ($catalog as $key => $entry) {
            if (!isset($placed[$key])) {
                $items[] = $entry;
            }
        }
    } else {
        $items = array_values($catalog);
    }

    throw ResponseException::encoded(['items' => $items]);
}

if ($action === 'menu_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_not_demo('Demo mode — writes disabled.');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
        http_response_code(400);
        admin_err('Invalid payload');
    }

    require_once __DIR__ . '/../config_store.php';
    $schemaRaw = config_get('schema') ?? [];
    $validKeys  = array_merge(['dashboard', 'calendar', 'files'], array_keys($schemaRaw['tables'] ?? []));
    $validTypes = ['dashboard', 'calendar', 'files', 'table'];

    $sanitized = [];
    foreach ($body['items'] as $entry) {
        $key  = $entry['key']  ?? '';
        $type = $entry['type'] ?? '';
        if (!in_array($key, $validKeys, true) || !in_array($type, $validTypes, true)) {
            continue;
        }
        $children = [];
        foreach ($entry['children'] ?? [] as $child) {
            $configKey = $child['key']  ?? '';
            $childType = $child['type'] ?? '';
            if (!in_array($configKey, $validKeys, true) || !in_array($childType, $validTypes, true)) {
                continue;
            }

            $children[] = ['key' => $configKey, 'children' => []];
        }
        $sanitized[] = ['key' => $key, 'children' => $children];
    }

    $menuUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $menuResult = config_save('menu', ['items' => $sanitized], null, $menuUserId);
    if ($menuResult['status'] !== 'ok') {
        http_response_code(500);
        admin_err($menuResult['error'] ?? 'Write failed');
    }
    admin_ok();
}

$allowedFiles = [
    'schema', 'dashboard', 'calendar', 'board', 'database', 'security',
    'workflows', 'files', 'views', 'automations', 'user_records',
];

$dbBackedFiles = [
    'automations', 'board', 'calendar', 'dashboard', 'files',
    'schema', 'user_records', 'views', 'workflows',
];

if ($action === 'get' && in_array($file, $allowedFiles, true)) {
    if (in_array($file, $dbBackedFiles, true)) {
        require_once __DIR__ . '/../config_store.php';
        $cfg = config_get($file);
        echo $cfg !== null ? json_encode($cfg) : json_encode(new stdClass());
        throw ResponseException::sent();
    }
    $filePath = __DIR__ . '/../../config/' . $file . '.json';
    if (file_exists($filePath)) {
        $fileContent = file_get_contents($filePath);

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
    throw ResponseException::sent();
}

if ($action === 'save' && in_array($file, $allowedFiles, true)) {
    require_not_demo('Saving ' . $file . ' configuration is disabled in Demo Mode.', 403);

    $data = file_get_contents('php://input');
    $filePath = __DIR__ . '/../../config/' . $file . '.json';
    $parsedData = json_decode($data, true);
    if (in_array($file, $dbBackedFiles, true)) {
        if (!is_array($parsedData)) {
            admin_err('Invalid JSON');
        }
        require_once __DIR__ . '/../config_store.php';

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $result = config_save($file, $parsedData, null, $userId);
        if ($result['status'] !== 'ok') {
            admin_err($result['error'] ?? 'Save failed');
        }
        admin_ok();
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
    throw ResponseException::sent();
}
