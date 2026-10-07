<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../includes/menu_builder.php';

$menuItems = menu_build_items();
$toggleSidebarLabel = htmlspecialchars(t('header.toggle_sidebar'), ENT_QUOTES, 'UTF-8');

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
            $icon = '<img src="assets/icons/material/table_chart_view.svg" alt="" />';
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
    <button id="sidebarToggle" class="menu-collapse-toggle" data-cy="sidebar-toggle"
            aria-label="<?= $toggleSidebarLabel ?>" aria-expanded="true">
        <img class="menu-collapse-icon-left" src="assets/icons/material/keyboard_double_arrow_left.svg" alt="">
        <img class="menu-collapse-icon-right" src="assets/icons/material/keyboard_double_arrow_right.svg" alt="">
    </button>
</nav>