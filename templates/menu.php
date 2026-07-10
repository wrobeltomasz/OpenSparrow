<?php
// templates/menu.php

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

// Validates icon paths against a local assets/ whitelist — blocks javascript:/data:
// payloads, external URLs (offline policy) and path traversal.
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
$schemaPath   = $includeDir . '/schema.json';
$tables       = safeReadJson($schemaPath)['tables'] ?? [];

$currentPage  = basename($_SERVER['PHP_SELF']);
$currentTable = substr($_GET['table'] ?? '', 0, 64);
$currentView  = substr($_GET['view'] ?? '', 0, 64);
$isWorkflows  = isset($_GET['workflows']);

$dashCfg  = loadMenuConfig('dashboard', $includeDir);
$calCfg   = loadMenuConfig('calendar', $includeDir);
$boardCfg = loadMenuConfig('board', $includeDir);
$filesCfg = loadMenuConfig('files', $includeDir);
$wfCfg    = loadMenuConfig('workflows', $includeDir);
$viewsCfg = loadMenuConfig('views', $includeDir);

// Build catalog: key → display data
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

// Board appears in the sidebar only once an admin has bound it to a table+status.
if (!empty($boardCfg['table']) && !empty($boardCfg['status_column'])) {
    $menuCatalog['board'] = [
        'type'   => 'board',
        'href'   => 'board.php',
        'name'   => $boardCfg['menu_name'] ?? 'Board',
        'icon'   => $boardCfg['menu_icon'] ?? 'assets/icons/account_tree.png',
        'hidden' => !empty($boardCfg['hidden']),
        'active' => $currentPage === 'board.php',
    ];
}

if (!empty($wfCfg['workflows'])) {
    $menuCatalog['workflows'] = [
        'type'      => 'workflows',
        'href'      => 'index.php?workflows=1',
        'name'      => $wfCfg['menu_name'] ?? 'Workflows',
        'icon'      => $wfCfg['menu_icon'] ?? '',
        'hidden'    => !empty($wfCfg['hidden']),
        'active'    => $isWorkflows && $currentPage === 'index.php',
        'data-page' => 'workflows',
    ];
}

$hasVisibleViews = false;
foreach ($viewsCfg['views'] ?? [] as $vConfig) {
    if (empty($vConfig['hidden'])) {
        $hasVisibleViews = true;
        break;
    }
}
if ($hasVisibleViews) {
    $menuCatalog['views'] = [
        'type'   => 'views',
        'href'   => 'views.php',
        'name'   => 'Views',
        'icon'   => 'assets/icons/table_chart_view.png',
        'hidden' => false,
        'active' => $currentPage === 'views.php',
    ];
}

foreach ($tables as $tName => $tConfig) {
    $isActive = false;
    if ($currentPage === 'index.php' && !$isWorkflows) {
        if ($currentTable === $tName) {
            $isActive = true;
        } elseif (empty($currentTable) && $tName === array_key_first($tables)) {
            $isActive = true;
        }
    }
    $menuCatalog[$tName] = [
        'type'   => 'table',
        'href'   => 'index.php?table=' . urlencode($tName),
        'name'   => $tConfig['display_name'] ?? $tName,
        'icon'   => $tConfig['icon'] ?? '',
        'hidden' => !empty($tConfig['hidden']),
        'active' => $isActive,
        'data-table' => $tName,
    ];
}

// Build structured item list (from menu.json if it exists, else flat catalog order)
$menuJson   = safeReadJson($includeDir . '/menu.json');
$menuItems  = [];
$menuPlaced = [];

if ($menuJson !== null && isset($menuJson['items']) && is_array($menuJson['items'])) {
    foreach ($menuJson['items'] as $entry) {
        $key = $entry['key'] ?? '';
        if ($key === '' || !isset($menuCatalog[$key])) {
            continue;
        }
        $item             = $menuCatalog[$key];
        $item['children'] = [];
        foreach ($entry['children'] ?? [] as $ce) {
            $ck = $ce['key'] ?? '';
            if ($ck === '' || !isset($menuCatalog[$ck])) {
                continue;
            }
            $item['children'][] = $menuCatalog[$ck];
            $menuPlaced[$ck]    = true;
        }
        $menuItems[]       = $item;
        $menuPlaced[$key]  = true;
    }
    foreach ($menuCatalog as $key => $entry) {
        if (!isset($menuPlaced[$key])) {
            $entry['children'] = [];
            $menuItems[]       = $entry;
        }
    }
} else {
    foreach ($menuCatalog as $entry) {
        $entry['children'] = [];
        $menuItems[]       = $entry;
    }
}

// Render a single menu link <a>
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
        $icon = renderMenuIcon((string)($item['icon'] ?? ''));
        if ($icon === '') {
            // No-emoji policy: fall back to a local PNG, never a Unicode glyph.
            $icon = '<img src="assets/icons/table_chart_view.png" alt="" />';
        }
        if (!empty($item['active'])) {
            $attrs .= ' aria-current="page"';
        }
        $name = htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '" class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"' . $attrs . ' data-tooltip="' . $name . '">'
             . $icon
             . '<span class="menu-text">' . $name . '</span>'
             . '</a>';
    }
}
?>
<nav id="menu" class="menu">
    <ul class="menu-list">

        <?php foreach ($menuItems as $item) : ?>
            <?php if ($item['hidden'] && empty($item['children'])) {
                continue;
            } ?>

            <?php if (!empty($item['children'])) : ?>
                <?php
                // Parent item with submenu: link navigates to grid, arrow toggles submenu
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
                    <!-- Main link: navigates to grid/page -->
                    <?php echo renderMenuLink($item); ?>
                    
                    <!-- Details toggle: only arrow for expanding/collapsing submenu -->
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
