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
    function loadMenuConfig(string $baseName, string $includeDir): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $baseName)) {
            return [];
        }

        require_once __DIR__ . '/../includes/config_store.php';
        $stored = config_get($baseName);
        if ($stored !== null) {
            return $stored;
        }
        $realBase = realpath($includeDir);
        if ($realBase === false) {
            return [];
        }
        $candidates = [
            $includeDir . '/' . $baseName . '.json',
            $includeDir . '/' . $baseName . '_config.json',
            $includeDir . '/config/' . $baseName . '.json',
            dirname($includeDir) . '/config/' . $baseName . '.json',
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

$includeDir   = __DIR__ . '/../config';
require_once __DIR__ . '/../includes/config_store.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$tables       = filter_tables_for_user((config_get('schema') ?? [])['tables'] ?? []);

$currentPage  = basename($_SERVER['PHP_SELF']);
$currentTable = substr($_GET['table'] ?? '', 0, 64);
$currentView     = substr($_GET['view'] ?? '', 0, 64);
$currentPrint    = substr($_GET['print'] ?? '', 0, 64);
$currentBoard    = substr($_GET['board'] ?? '', 0, 64);
$currentWorkflow = substr($_GET['workflow'] ?? '', 0, 64);
$isWorkflows     = isset($_GET['workflows']);

$dashCfg  = loadMenuConfig('dashboard', $includeDir);
$calCfg   = loadMenuConfig('calendar', $includeDir);
$boardCfg = loadMenuConfig('board', $includeDir);
$filesCfg = loadMenuConfig('files', $includeDir);
$workflowsConfig    = loadMenuConfig('workflows', $includeDir);
$viewsCfg = loadMenuConfig('views', $includeDir);

$menuCatalog = [
    'dashboard' => [
        'type'   => 'dashboard',
        'href'   => 'dashboard.php',
        'name'   => $dashCfg['menu_name']  ?? 'Dashboard',
        'icon'   => $dashCfg['menu_icon']  ?? 'assets/icons/dashboard.png',
        'hidden' => !empty($dashCfg['hidden']),
        'active' => $currentPage === 'dashboard.php',
    ],
    'calendar' => [
        'type'   => 'calendar',
        'href'   => 'calendar.php',
        'name'   => $calCfg['menu_name']   ?? 'Calendar',
        'icon'   => $calCfg['menu_icon']   ?? 'assets/icons/calendar.png',
        'hidden' => !empty($calCfg['hidden']),
        'active' => $currentPage === 'calendar.php',
    ],
    'files' => [
        'type'   => 'files',
        'href'   => 'files.php',
        'name'   => $filesCfg['menu_name'] ?? 'Files',
        'icon'   => $filesCfg['menu_icon'] ?? 'assets/icons/folder_open.png',
        'hidden' => !empty($filesCfg['hidden']),
        'active' => $currentPage === 'files.php',
    ],
];

$boardChildren = [];

foreach (filter_by_user_access('boards', $boardCfg['boards'] ?? []) as $bItem) {
    if (empty($bItem['table']) || empty($bItem['status_column']) || !empty($bItem['hidden'])) {
        continue;
    }
    if (!user_can_access_table((string) $bItem['table'])) {
        continue;
    }
    $boardId             = (string) ($bItem['id'] ?? '');
    if ($boardId === '') {
        continue;
    }
    $boardChildren[] = [
        'type'   => 'board',
        'href'   => 'board.php?board=' . urlencode($boardId),
        'name'   => $bItem['menu_name'] ?? 'Board',
        'icon'   => $bItem['menu_icon'] ?? '',
        'hidden' => false,
        'active' => $currentPage === 'board.php' && $currentBoard === $boardId,
    ];
}
if (!empty($boardChildren)) {
    $menuCatalog['board'] = [
        'type'     => 'board',
        'href'     => $boardChildren[0]['href'],
        'name'     => $boardCfg['menu_name'] ?? 'Board',
        'icon'     => $boardCfg['menu_icon'] ?? 'assets/icons/account_tree.png',
        'hidden'   => !empty($boardCfg['hidden']),
        'active'   => $currentPage === 'board.php',
        'children' => $boardChildren,
    ];
}

$workflowChildren = [];
foreach (filter_by_user_access('workflows', $workflowsConfig['workflows'] ?? []) as $wfItem) {
    $wfId = (string) ($wfItem['id'] ?? '');
    if ($wfId === '' || !workflow_tables_in_scope($wfItem)) {
        continue;
    }
    $workflowChildren[] = [
        'type'             => 'workflow',
        'href'             => 'index.php?workflows=1&workflow=' . urlencode($wfId),
        'name'             => $wfItem['title'] ?? $wfId,
        'icon'             => $wfItem['icon'] ?? '',
        'hidden'           => false,
        'active'           => $isWorkflows && $currentPage === 'index.php' && $currentWorkflow === $wfId,
        'data-workflow-id' => $wfId,
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
foreach ($viewsCfg['views'] ?? [] as $vName => $vConfig) {
    if (!empty($vConfig['hidden'])) {
        continue;
    }
    if (!user_can_access_view((string) $vName)) {
        continue;
    }
    $vName          = (string) $vName;
    $viewChildren[] = [
        'type'   => 'view',
        'href'   => 'views.php?view=' . urlencode($vName),
        'name'   => $vConfig['menu_name'] ?? ($vConfig['display_name'] ?? $vName),
        'icon'   => $vConfig['icon'] ?? '',
        'hidden' => false,
        'active' => $currentPage === 'views.php' && $currentView === $vName,
    ];
}
if (!empty($viewChildren)) {
    $menuCatalog['views'] = [
        'type'     => 'views',
        'href'     => 'views.php',
        'name'     => $viewsCfg['menu_name'] ?? 'Views',
        'icon'     => $viewsCfg['menu_icon'] ?? 'assets/icons/table_chart_view.png',
        'hidden'   => !empty($viewsCfg['hidden']),
        'active'   => $currentPage === 'views.php' && $currentView === '',
        'children' => $viewChildren,
    ];
}

$printCfg      = loadMenuConfig('print', $includeDir);
$printChildren = [];
foreach ($printCfg['prints'] ?? [] as $pName => $pConfig) {
    if (!empty($pConfig['hidden'])) {
        continue;
    }
    if (!user_can_access_print((string) $pName)) {
        continue;
    }
    $pName           = (string) $pName;
    $printChildren[] = [
        'type'   => 'print',
        'href'   => 'print.php?print=' . urlencode($pName),
        'name'   => $pConfig['menu_name'] ?? ($pConfig['display_name'] ?? $pName),
        'icon'   => $pConfig['icon'] ?? '',
        'hidden' => false,
        'active' => $currentPage === 'print.php' && $currentPrint === $pName,
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
        $attrs   = '';
        if (!empty($item['data-table'])) {
            $attrs = ' data-table="' . htmlspecialchars($item['data-table'], ENT_QUOTES, 'UTF-8') . '"';
        }
        if (!empty($item['data-page'])) {
            $attrs .= ' data-page="' . htmlspecialchars($item['data-page'], ENT_QUOTES, 'UTF-8') . '"';
        }
        if (!empty($item['data-workflow-id'])) {
            $attrs .= ' data-workflow-id="' . htmlspecialchars($item['data-workflow-id'], ENT_QUOTES, 'UTF-8') . '"';
        }
        $icon = renderMenuIcon((string)($item['icon'] ?? ''));
        if ($icon === '') {
            $icon = '<img src="assets/icons/table_chart_view.png" alt="" />';
        }
        if (!empty($item['active'])) {
            $attrs .= ' aria-current="page"';
        }
        $name = htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '" class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"'
             . $attrs . ' data-tooltip="' . $name . '">'
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
