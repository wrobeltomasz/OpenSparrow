<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

if (!function_exists('safeReadJson')) {
    function safeReadJson(string $path, int $maxBytes = 524288): ?array
    {
        if (!file_exists($path) || filesize($path) > $maxBytes) {
            return null;
        }
        $content = file_get_contents($path, false, null, 0, $maxBytes);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('loadMenuConfig')) {
    function loadMenuConfig(string $baseName, string $includeDirectory): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $baseName)) {
            return [];
        }

        require_once __DIR__ . '/../includes/config_store.php';
        $stored = config_get($baseName);
        if ($stored !== null) {
            return $stored;
        }
        $realBase = realpath($includeDirectory);
        if ($realBase === false) {
            return [];
        }
        $candidates = [
            $includeDirectory . '/' . $baseName . '.json',
            $includeDirectory . '/' . $baseName . '_config.json',
            $includeDirectory . '/config/' . $baseName . '.json',
            dirname($includeDirectory) . '/config/' . $baseName . '.json',
        ];
        foreach ($candidates as $path) {
            $realPath = realpath($path);
            if ($realPath === false || !str_starts_with($realPath, $realBase)) {
                continue;
            }
            $decoded = safeReadJson($realPath);
            if ($decoded !== null) {
                return $decoded;
            }
        }
        return [];
    }
}

if (!function_exists('renderMenuIcon')) {
    function renderMenuIcon(string $icon): string
    {
        if (str_contains($icon, '/') || str_contains($icon, '.')) {
            if (
                str_contains($icon, '..')
                || !preg_match('#^assets/[a-z0-9_\-/.]+\.(png|svg|gif|jpe?g|webp)$#i', $icon)
            ) {
                return '';
            }
            return '<img src="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" alt="" />';
        }
        return '<span class="menu-icon-span">'
             . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

$includeDirectory   = __DIR__ . '/../config';
require_once __DIR__ . '/../includes/config_store.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$tables       = filter_tables_for_user((config_get('schema') ?? [])['tables'] ?? []);

$queryParameters = os_request()->queryAll();
$currentPage  = basename($_SERVER['PHP_SELF']);
$currentTable = substr(os_query_string('table'), 0, 64);
$currentView     = substr(os_query_string('view'), 0, 64);
$currentPrint    = substr(os_query_string('print'), 0, 64);
$currentBoard    = substr(os_query_string('board'), 0, 64);
$currentWorkflow = substr(os_query_string('workflow'), 0, 64);
$isWorkflows     = isset($queryParameters['workflows']);

$dashConfig  = loadMenuConfig('dashboard', $includeDirectory);
$calendarConfig   = loadMenuConfig('calendar', $includeDirectory);
$boardConfig = loadMenuConfig('board', $includeDirectory);
$filesConfig = loadMenuConfig('files', $includeDirectory);
$workflowsConfig    = loadMenuConfig('workflows', $includeDirectory);
$viewsConfig = loadMenuConfig('views', $includeDirectory);

$menuCatalog = [
    'dashboard' => [
        'type'   => 'dashboard',
        'href'   => 'dashboard.php',
        'name'   => $dashConfig['menu_name']  ?? 'Dashboard',
        'icon'   => $dashConfig['menu_icon']  ?? 'assets/icons/dashboard.png',
        'hidden' => !empty($dashConfig['hidden']),
        'active' => $currentPage === 'dashboard.php',
    ],
    'calendar' => [
        'type'   => 'calendar',
        'href'   => 'calendar.php',
        'name'   => $calendarConfig['menu_name']   ?? 'Calendar',
        'icon'   => $calendarConfig['menu_icon']   ?? 'assets/icons/calendar.png',
        'hidden' => !empty($calendarConfig['hidden']),
        'active' => $currentPage === 'calendar.php',
    ],
    'files' => [
        'type'   => 'files',
        'href'   => 'files.php',
        'name'   => $filesConfig['menu_name'] ?? 'Files',
        'icon'   => $filesConfig['menu_icon'] ?? 'assets/icons/folder_open.png',
        'hidden' => !empty($filesConfig['hidden']),
        'active' => $currentPage === 'files.php',
    ],
];

$boardChildren = [];

foreach (filter_by_user_access('boards', $boardConfig['boards'] ?? []) as $boardItem) {
    if (empty($boardItem['table']) || empty($boardItem['status_column']) || !empty($boardItem['hidden'])) {
        continue;
    }
    if (!user_can_access_table((string) $boardItem['table'])) {
        continue;
    }
    $boardId             = (string) ($boardItem['id'] ?? '');
    if ($boardId === '') {
        continue;
    }
    $boardChildren[] = [
        'type'   => 'board',
        'href'   => 'board.php?board=' . urlencode($boardId),
        'name'   => $boardItem['menu_name'] ?? 'Board',
        'icon'   => $boardItem['menu_icon'] ?? '',
        'hidden' => false,
        'active' => $currentPage === 'board.php' && $currentBoard === $boardId,
    ];
}
if (!empty($boardChildren)) {
    $menuCatalog['board'] = [
        'type'     => 'board',
        'href'     => $boardChildren[0]['href'],
        'name'     => $boardConfig['menu_name'] ?? 'Board',
        'icon'     => $boardConfig['menu_icon'] ?? 'assets/icons/account_tree.png',
        'hidden'   => !empty($boardConfig['hidden']),
        'active'   => $currentPage === 'board.php',
        'children' => $boardChildren,
    ];
}

$workflowChildren = [];
foreach (filter_by_user_access('workflows', $workflowsConfig['workflows'] ?? []) as $workflowItem) {
    $workflowId = (string) ($workflowItem['id'] ?? '');
    if ($workflowId === '' || !workflow_tables_in_scope($workflowItem)) {
        continue;
    }
    $workflowChildren[] = [
        'type'             => 'workflow',
        'href'             => 'index.php?workflows=1&workflow=' . urlencode($workflowId),
        'name'             => $workflowItem['title'] ?? $workflowId,
        'icon'             => $workflowItem['icon'] ?? '',
        'hidden'           => false,
        'active'           => $isWorkflows && $currentPage === 'index.php' && $currentWorkflow === $workflowId,
        'data-workflow-id' => $workflowId,
    ];
}

if (!empty($workflowChildren)) {
    $menuCatalog['workflows'] = [
        'type'      => 'workflows',
        'href'      => 'index.php?workflows=1',
        'name'      => $workflowsConfig['menu_name'] ?? 'Workflows',
        'icon'      => $workflowsConfig['menu_icon'] ?? '',
        'hidden'    => !empty($workflowsConfig['hidden']),
        'active'    => $isWorkflows && $currentPage === 'index.php' && $currentWorkflow === '',
        'data-page' => 'workflows',
        'children'  => $workflowChildren,
    ];
}

$viewChildren = [];
foreach ($viewsConfig['views'] ?? [] as $viewName => $viewConfig) {
    if (!empty($viewConfig['hidden'])) {
        continue;
    }
    if (!user_can_access_view((string) $viewName)) {
        continue;
    }
    $viewName          = (string) $viewName;
    $viewChildren[] = [
        'type'   => 'view',
        'href'   => 'views.php?view=' . urlencode($viewName),
        'name'   => $viewConfig['menu_name'] ?? ($viewConfig['display_name'] ?? $viewName),
        'icon'   => $viewConfig['icon'] ?? '',
        'hidden' => false,
        'active' => $currentPage === 'views.php' && $currentView === $viewName,
    ];
}
if (!empty($viewChildren)) {
    $menuCatalog['views'] = [
        'type'     => 'views',
        'href'     => 'views.php',
        'name'     => $viewsConfig['menu_name'] ?? 'Views',
        'icon'     => $viewsConfig['menu_icon'] ?? 'assets/icons/table_chart_view.png',
        'hidden'   => !empty($viewsConfig['hidden']),
        'active'   => $currentPage === 'views.php' && $currentView === '',
        'children' => $viewChildren,
    ];
}

$printsConfig      = loadMenuConfig('print', $includeDirectory);
$printChildren = [];
foreach ($printsConfig['prints'] ?? [] as $printName => $printConfig) {
    if (!empty($printConfig['hidden'])) {
        continue;
    }
    if (!user_can_access_print((string) $printName)) {
        continue;
    }
    $printName           = (string) $printName;
    $printChildren[] = [
        'type'   => 'print',
        'href'   => 'print.php?print=' . urlencode($printName),
        'name'   => $printConfig['menu_name'] ?? ($printConfig['display_name'] ?? $printName),
        'icon'   => $printConfig['icon'] ?? '',
        'hidden' => false,
        'active' => $currentPage === 'print.php' && $currentPrint === $printName,
    ];
}
if (!empty($printChildren)) {
    $menuCatalog['print'] = [
        'type'     => 'print',
        'href'     => 'print.php',
        'name'     => 'Print',
        'icon'     => 'assets/icons/picture_as_pdf.png',
        'hidden'   => false,
        'active'   => $currentPage === 'print.php' && $currentPrint === '',
        'children' => $printChildren,
    ];
}

foreach ($tables as $tableName => $tableConfig) {
    $isActive = false;
    if ($currentPage === 'index.php' && !$isWorkflows) {
        if ($currentTable === $tableName) {
            $isActive = true;
        } elseif (empty($currentTable) && $tableName === array_key_first($tables)) {
            $isActive = true;
        }
    }
    $menuCatalog[$tableName] = [
        'type'   => 'table',
        'href'   => 'index.php?table=' . urlencode($tableName),
        'name'   => $tableConfig['display_name'] ?? $tableName,
        'icon'   => $tableConfig['icon'] ?? '',
        'hidden' => !empty($tableConfig['hidden']),
        'active' => $isActive,
        'data-table' => $tableName,
    ];
}

$menuJson   = config_get('menu');
$menuItems  = [];
$menuPlaced = [];

if ($menuJson !== null && isset($menuJson['items']) && is_array($menuJson['items'])) {
    foreach ($menuJson['items'] as $entry) {
        $key = $entry['key'] ?? '';
        if ($key === '' || !isset($menuCatalog[$key])) {
            continue;
        }
        $item             = $menuCatalog[$key];

        $item['children'] = $item['children'] ?? [];
        foreach ($entry['children'] ?? [] as $configEntry) {
            $configKey = $configEntry['key'] ?? '';
            if ($configKey === '' || !isset($menuCatalog[$configKey])) {
                continue;
            }
            $item['children'][] = $menuCatalog[$configKey];
            $menuPlaced[$configKey]    = true;
        }
        $menuItems[]       = $item;
        $menuPlaced[$key]  = true;
    }
    foreach ($menuCatalog as $key => $entry) {
        if (!isset($menuPlaced[$key])) {
            $entry['children'] = $entry['children'] ?? [];
            $menuItems[]       = $entry;
        }
    }
} else {
    foreach ($menuCatalog as $entry) {
        $entry['children'] = $entry['children'] ?? [];
        $menuItems[]       = $entry;
    }
}

if (!function_exists('renderMenuLink')) {
    function renderMenuLink(array $item, string $extraClass = ''): string
    {
        $classes = trim('custom-nav-link ' . ($item['active'] ? 'active' : '') . ' ' . $extraClass);
        $href    = htmlspecialchars($item['href'] ?? '#', ENT_QUOTES, 'UTF-8');
        $attributes   = '';
        if (!empty($item['data-table'])) {
            $attributes = ' data-table="' . htmlspecialchars($item['data-table'], ENT_QUOTES, 'UTF-8') . '"';
        }
        if (!empty($item['data-page'])) {
            $attributes .= ' data-page="' . htmlspecialchars($item['data-page'], ENT_QUOTES, 'UTF-8') . '"';
        }
        if (!empty($item['data-workflow-id'])) {
            $attributes .= ' data-workflow-id="'
                . htmlspecialchars($item['data-workflow-id'], ENT_QUOTES, 'UTF-8') . '"';
        }
        $icon = renderMenuIcon((string)($item['icon'] ?? ''));
        if ($icon === '') {
            $icon = '<img src="assets/icons/table_chart_view.png" alt="" />';
        }
        if (!empty($item['active'])) {
            $attributes .= ' aria-current="page"';
        }
        $name = htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '" class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"'
             . $attributes . ' data-tooltip="' . $name . '">'
             . $icon
             . '<span class="menu-text">' . $name . '</span>'
             . '</a>';
    }
}
?>
<nav id="menu" class="menu">
    <ul class="menu-list">

        <?php foreach ($menuItems as $item) : ?>
            <?php if ($item['hidden']) {
                continue;
            } ?>

            <?php if (!empty($item['children'])) : ?>
                <?php

                $anyChildActive = false;
                foreach ($item['children'] as $child) {
                    if (!empty($child['active'])) {
                        $anyChildActive = true;
                        break;
                    }
                }
                $isOpen = $anyChildActive || (!empty($item['active']));
                $toggleLabel = htmlspecialchars(
                    t('header.toggle_submenu', ['name' => $item['name'] ?? '']),
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>
                <li class="menu-has-children">
                    <?php echo renderMenuLink($item); ?>

                    <details class="menu-submenu-details"<?php echo $isOpen ? ' open' : ''; ?>>
                        <summary class="menu-toggle-arrow" aria-label="<?php echo $toggleLabel; ?>">
                            <span class="menu-arrow" aria-hidden="true">▾</span>
                        </summary>
                        <ul class="menu-submenu">
                            <?php foreach ($item['children'] as $child) : ?>
                                <?php if ($child['hidden']) {
                                    continue;
                                } ?>
                                <li><?php echo renderMenuLink($child); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                </li>
            <?php elseif (!$item['hidden']) : ?>
                <li><?php echo renderMenuLink($item); ?></li>
            <?php endif; ?>
        <?php endforeach; ?>

    </ul>
</nav>
