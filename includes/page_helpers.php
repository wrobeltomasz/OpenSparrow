<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.
//
// page_helpers.php — Shared HTML fragments for frontend page controllers
// os_header_search()        — header search pill (search & filter UI standard)
// os_header_filters()       — header filter-chip container
// os_header_clear_filters() — the #clearFilters button (last header control on every page)
// os_inline_globals()       — nonce'd <script> exposing window.* globals (JSON_HEX_* hardened)
// os_module_script()        — nonce'd <script type="module"> tag with ?v=filemtime cache busting
// os_module_graph()         — one shared ?v= + import map for a whole ES module tree
// os_import_map()           — renders that map; must precede the first module script
// os_avatar_color()         — palette lookup for the initial-and-color avatar
// os_m2m_group()            — collapsible searchable many-to-many picker (create.php + edit.php)
// Loaded via bootstrap.php; keep ids/classes stable — Cypress specs depend on them.

declare(strict_types=1);

// Search pill for the blue app header. Placeholder defaults to the shared
// grid.search_placeholder i18n key; pass an explicit string to override.
function os_header_search(string $id, ?string $placeholder = null): string
{
    $ph = htmlspecialchars($placeholder ?? t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8');
    return '<input type="search" id="' . $id . '" placeholder="' . $ph . '"'
        . ' aria-label="' . $ph . '">';
}

// Filter-chip container. The class must be listed in the header chip-container
// selector group in styles.css (single line + horizontal scroll — no wrapping).
function os_header_filters(string $id, string $class): string
{
    return '<div id="' . $id . '" class="' . $class . '"></div>';
}

// Clear-filters button — hidden by default, shown by page JS while any
// filter/search is active. Always the last header control.
function os_header_clear_filters(): string
{
    $label = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
    return '<button id="clearFilters" hidden title="' . $label . '">' . $label . '</button>';
}

// Nonce'd inline <script> exposing window.<name> = <json> globals.
// JSON_HEX_* flags make the values safe inside a <script> context (CLAUDE.md rule).
function os_inline_globals(array $vars, string $nonce): string
{
    $flags = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $js    = '';
    foreach ($vars as $name => $value) {
        $js .= '    window.' . $name . ' = ' . json_encode($value, $flags) . ";\n";
    }
    return '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">' . "\n"
        . $js . '</script>' . "\n";
}

// Nonce'd module <script src> tag with ?v=filemtime cache busting. $versionFile
// overrides which file's mtime busts the cache (defaults to $src itself); both
// resolve relative to the executing page's directory (public/).
function os_module_script(string $src, string $nonce, ?string $versionFile = null): string
{
    $v = (string) @filemtime($versionFile ?? $src);
    return '<script type="module" src="' . $src . '?v=' . $v . '"'
        . ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
}

/**
 * Build one cache-busting version plus an import map covering a whole ES module graph.
 *
 * Entry scripts carry a "?v=", but the modules they import do not — so after an upgrade
 * the browser keeps serving every non-entry module from cache and the shipped change
 * never reaches the user. The symptom ("this feature does nothing") gives no hint that
 * stale JavaScript is the cause, and no user knows to hard-refresh.
 *
 * Every module must get the SAME version: a module reachable under two URLs is
 * instantiated twice by the browser (duplicate listeners, split state, lost saves).
 *
 * @param array<string,string> $groups url prefix (as written in import specifiers,
 *                                     resolved against the document base) => filesystem dir
 * @return array{version:int,imports:array<string,string>}
 */
function os_module_graph(array $groups): array
{
    // Memoised: layout.php and any caller asking for the version must not each
    // walk the asset tree on every request.
    static $cache = [];
    $cacheKey = md5(serialize($groups));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $files = [];
    foreach ($groups as $urlPrefix => $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $files[$urlPrefix . $rel] = (int) $file->getMTime();
        }
    }

    $version = $files === [] ? 0 : max($files);
    $imports = [];
    foreach (array_keys($files) as $spec) {
        $imports[$spec] = $spec . '?v=' . $version;
    }
    ksort($imports);

    return $cache[$cacheKey] = ['version' => $version, 'imports' => $imports];
}

// The frontend module tree, in one place so every caller agrees on its shape.
function os_fe_module_graph(): array
{
    return os_module_graph(['./assets/js/' => __DIR__ . '/../public/assets/js']);
}

// Renders the <script type="importmap"> for os_module_graph(). Must appear before the
// first module script on the page. Pass '' as $nonce on pages that send no CSP.
function os_import_map(array $imports, string $nonce = ''): string
{
    $json = json_encode(
        ['imports' => $imports],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
    );
    $nonceAttr = $nonce !== ''
        ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"'
        : '';
    return '<script type="importmap"' . $nonceAttr . '>' . $json . '</script>' . "\n";
}

// Avatar palette. An avatar is the user's initial on a colour of their choice;
// spw_users.avatar_id stores the 1-based index into this palette (NULL = default
// slate). Every colour is dark enough for white text at 4.5:1 or better.
//
// KEEP IN SYNC with AVATAR_COLORS in public/assets/js/avatar.js — same order,
// same values. PHP renders the header avatar server-side, JS renders every other
// one, and a drift would show the same user in two different colours.
const OS_AVATAR_COLORS = [
    '#364B60', '#1F6F8B', '#2E7D6B', '#3F7D3F', '#6B7D2E', '#8A6D1F',
    '#A65A2E', '#B04A4A', '#A33F6B', '#7A4FA3', '#4F55A3', '#2F6FA3',
    '#455A64', '#00695C', '#2E7D32', '#558B2F', '#9E7B0A', '#C05621',
    '#B23A48', '#8E3B6B', '#5E35B1', '#3949AB', '#0277BD', '#00838F',
];

// Resolves an avatar_id (1..24, or NULL/out of range) to a palette colour.
function os_avatar_color(?int $avatarId): string
{
    if ($avatarId === null || $avatarId < 1 || $avatarId > count(OS_AVATAR_COLORS)) {
        return OS_AVATAR_COLORS[0];
    }
    return OS_AVATAR_COLORS[$avatarId - 1];
}

// Options above this count get a search box inside the picker panel.
const OS_M2M_SEARCH_THRESHOLD = 10;

// Chips rendered in the collapsed summary before it falls back to "N selected".
const OS_M2M_SUMMARY_CHIPS = 3;

// One many-to-many relation as a collapsible <details> picker: a field-shaped
// summary listing the current selection, and a scrollable checkbox panel with
// search + select all/clear. The checkboxes are plain `m2m_<index>[]` inputs and
// stay in the DOM while collapsed, so POST handling is unchanged and the control
// still works with JS disabled (assets/js/edit/m2m-picker.js only enhances it).
// $options are m2m_options() rows, $selected the m2m_selected() id list.
function os_m2m_group(int $index, array $cfg, array $options, array $selected, bool $readOnly): string
{
    $esc   = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $label = $esc((string)($cfg['label'] ?? 'Related'));

    $html = '<div class="m2m-group">' . "\n"
        . '<div class="m2m-group-label">' . $label . '</div>' . "\n";

    if ($options === []) {
        return $html . '<p class="m2m-empty">' . $esc(t('form.no_options')) . '</p>' . "\n</div>\n";
    }

    $selectedLabels = [];
    foreach ($options as $opt) {
        if (in_array((string)$opt['id'], $selected, true)) {
            $selectedLabels[] = (string)$opt['label'];
        }
    }

    $html .= '<details class="m2m-picker"'
        . ' data-none-text="' . $esc(t('form.m2m_none_selected')) . '">' . "\n"
        . '<summary class="m2m-toggle">'
        . '<span class="m2m-summary">' . os_m2m_summary($selectedLabels) . '</span>'
        . '<span class="m2m-chevron" aria-hidden="true">▾</span>'
        . '</summary>' . "\n"
        . '<div class="m2m-panel">' . "\n";

    // Short lists need no search box, and a read-only record needs no bulk
    // toggles — with neither, the head row is skipped entirely.
    $head = '';
    if (count($options) > OS_M2M_SEARCH_THRESHOLD) {
        $ph = $esc(t('form.m2m_search'));
        $head .= '<input type="text" class="m2m-search" placeholder="' . $ph . '" aria-label="' . $ph . '">';
    }
    if (!$readOnly) {
        $head .= '<button type="button" class="m2m-link" data-m2m-all>' . $esc(t('form.m2m_select_all')) . '</button>'
            . '<button type="button" class="m2m-link" data-m2m-none>' . $esc(t('form.m2m_clear')) . '</button>';
    }
    if ($head !== '') {
        $html .= '<div class="m2m-panel-head">' . $head . '</div>' . "\n";
    }

    $html .= '<div class="m2m-options">' . "\n";
    foreach ($options as $opt) {
        $html .= '<label class="m2m-option">'
            . '<input type="checkbox" name="m2m_' . $index . '[]"'
            . ' value="' . $esc((string)$opt['id']) . '"'
            . (in_array((string)$opt['id'], $selected, true) ? ' checked' : '')
            . ($readOnly ? ' disabled' : '') . '>'
            . '<span class="m2m-option-label">' . $esc((string)$opt['label']) . '</span>'
            . '</label>' . "\n";
    }
    $html .= '</div>' . "\n"
        . '<p class="m2m-no-matches" hidden>' . $esc(t('form.m2m_no_matches')) . '</p>' . "\n"
        . '</div>' . "\n</details>\n</div>\n";

    return $html;
}

// Collapsed-summary contents: up to OS_M2M_SUMMARY_CHIPS chips, then a "+N" chip.
// KEEP IN SYNC with renderSummary() in public/assets/js/edit/m2m-picker.js.
function os_m2m_summary(array $labels): string
{
    if ($labels === []) {
        return '<span class="m2m-summary-empty">' . htmlspecialchars(t('form.m2m_none_selected'), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    if (count($labels) > OS_M2M_SUMMARY_CHIPS + 1) {
        $shown = array_slice($labels, 0, OS_M2M_SUMMARY_CHIPS);
        $more  = count($labels) - OS_M2M_SUMMARY_CHIPS;
    } else {
        $shown = $labels;
        $more  = 0;
    }

    $html = '';
    foreach ($shown as $l) {
        $html .= '<span class="m2m-chip">' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ($more > 0) {
        $html .= '<span class="m2m-chip m2m-chip-more">+' . $more . '</span>';
    }
    return $html;
}
