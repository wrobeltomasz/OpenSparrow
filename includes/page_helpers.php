<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function os_asset_fallback_version(): string
{
    if (defined('ASSET_VERSION')) {
        return (string) ASSET_VERSION;
    }
    require_once __DIR__ . '/version.php';
    return (string) OPENSPARROW_VERSION;
}

function os_asset_path(string $path): string
{
    if ($path === '') {
        return $path;
    }
    if ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[a-zA-Z]:[\\\\/]#', $path) === 1) {
        return $path;
    }
    return __DIR__ . '/../public/' . $path;
}

function asset_version(string $path): string
{
    static $cache = [];
    if (isset($cache[$path])) {
        return $cache[$path];
    }

    $file  = os_asset_path($path);
    $mtime = is_file($file) ? filemtime($file) : false;
    if ($mtime === false) {
        error_log('[assets] cache-busting fallback, asset not readable: ' . $file);
        return $cache[$path] = os_asset_fallback_version();
    }

    return $cache[$path] = (string) $mtime;
}

function os_require_access(string $scope, string $name): void
{
    require_once __DIR__ . '/api_helpers.php';
    if (user_can_access($scope, $name)) {
        return;
    }
    throw new \App\Exception\RedirectException('index.php');
}

function os_require_table_access(string $table): void
{
    os_require_access('tables', $table);
}

function os_validated_table_name(string $table): string
{
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/', $table) !== 1) {
        throw new \App\Exception\BadRequestException('Invalid table.');
    }
    return $table;
}

function os_validated_record_id(string $id): int
{
    if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
        throw new \App\Exception\BadRequestException('Invalid record id.');
    }
    return (int) $id;
}

function os_header_search(string $id, ?string $placeholder = null): string
{
    $escapedPlaceholder = htmlspecialchars($placeholder ?? t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8');
    return '<input type="search" id="' . $id . '" placeholder="' . $escapedPlaceholder . '"'
        . ' aria-label="' . $escapedPlaceholder . '">';
}

function os_header_input(string $id, ?string $placeholder = null): string
{
    $escapedPlaceholder = htmlspecialchars($placeholder ?? t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8');
    return '<input type="text" id="' . $id . '" placeholder="' . $escapedPlaceholder . '"'
        . ' aria-label="' . $escapedPlaceholder . '">';
}

function os_header_label(string $forId, string $text, string $class = ''): string
{
    $classAttribute = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<label for="' . $forId . '"' . $classAttribute . '>'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</label>';
}

function os_header_select(string $id, array $options, bool $hidden = false): string
{
    $markup = '<select id="' . $id . '"' . ($hidden ? ' hidden' : '') . '>';
    foreach ($options as $value => $label) {
        $markup .= '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $markup . '</select>';
}

function os_header_filters(string $id, string $class): string
{
    $classAttribute = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<div id="' . $id . '"' . $classAttribute . '></div>';
}

function os_header_clear_filters(): string
{
    $label = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
    return '<button id="clearFilters" hidden title="' . $label . '">' . $label . '</button>';
}

function os_inline_globals(array $variables, string $nonce): string
{
    $flags = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $javaScript    = '';
    foreach ($variables as $name => $value) {
        $javaScript .= '    window.' . $name . ' = ' . json_encode($value, $flags) . ";\n";
    }
    return '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">' . "\n"
        . $javaScript . '</script>' . "\n";
}

function os_module_script(string $source, string $nonce, ?string $versionFile = null): string
{
    $assetVersion = str_starts_with($source, 'assets/js/') || str_starts_with($source, './assets/js/')
        ? (string) os_fe_module_graph()['version']
        : asset_version($versionFile ?? $source);
    $nonceAttribute = $nonce !== ''
        ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"'
        : '';
    return '<script type="module" src="' . $source . '?v=' . $assetVersion . '"'
        . $nonceAttribute . '></script>' . "\n";
}

function os_module_graph(array $groups): array
{
    static $cache = [];
    $cacheKey = md5(serialize($groups));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $files = [];
    foreach ($groups as $urlPrefix => $directory) {
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') {
                continue;
            }
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $files[$urlPrefix . $relativePath] = (int) $file->getMTime();
        }
    }

    $version = $files === [] ? 0 : max($files);
    $imports = [];
    foreach (array_keys($files) as $specification) {
        $imports[$specification] = $specification . '?v=' . $version;
    }
    ksort($imports);

    return $cache[$cacheKey] = ['version' => $version, 'imports' => $imports];
}

function os_fe_module_graph(): array
{
    return os_module_graph(['./assets/js/' => __DIR__ . '/../public/assets/js']);
}

function os_import_map(array $imports, string $nonce = ''): string
{
    $json = json_encode(
        ['imports' => $imports],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
    );
    $nonceAttribute = $nonce !== ''
        ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"'
        : '';
    return '<script type="importmap"' . $nonceAttribute . '>' . $json . '</script>' . "\n";
}

const OS_AVATAR_COLORS = [
    '#364B60', '#1F6F8B', '#2E7D6B', '#3F7D3F', '#6B7D2E', '#8A6D1F',
    '#A65A2E', '#B04A4A', '#A33F6B', '#7A4FA3', '#4F55A3', '#2F6FA3',
    '#455A64', '#00695C', '#2E7D32', '#558B2F', '#9E7B0A', '#C05621',
    '#B23A48', '#8E3B6B', '#5E35B1', '#3949AB', '#0277BD', '#00838F',
];

function os_avatar_color(?int $avatarId): string
{
    if ($avatarId === null || $avatarId < 1 || $avatarId > count(OS_AVATAR_COLORS)) {
        return OS_AVATAR_COLORS[0];
    }
    return OS_AVATAR_COLORS[$avatarId - 1];
}

const OS_M2M_SEARCH_THRESHOLD = 10;

const OS_M2M_SUMMARY_CHIPS = 3;

function os_m2m_group(int $index, array $config, array $options, array $selected, bool $readOnly): string
{
    $escape   = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $label = $escape((string)($config['label'] ?? 'Related'));

    $html = '<div class="m2m-group">' . "\n"
        . '<div class="m2m-group-label">' . $label . '</div>' . "\n";

    if ($options === []) {
        return $html . '<p class="m2m-empty">' . $escape(t('form.no_options')) . '</p>' . "\n</div>\n";
    }

    $selectedLabels = [];
    foreach ($options as $option) {
        if (in_array((string)$option['id'], $selected, true)) {
            $selectedLabels[] = (string)$option['label'];
        }
    }

    $html .= '<details class="m2m-picker"'
        . ' data-none-text="' . $escape(t('form.m2m_none_selected')) . '">' . "\n"
        . '<summary class="m2m-toggle">'
        . '<span class="m2m-summary">' . os_m2m_summary($selectedLabels) . '</span>'
        . '<span class="m2m-chevron" aria-hidden="true">▾</span>'
        . '</summary>' . "\n"
        . '<div class="m2m-panel">' . "\n";

    $head = '';
    if (count($options) > OS_M2M_SEARCH_THRESHOLD) {
        $escapedPlaceholder = $escape(t('form.m2m_search'));
        $head .= '<input type="text" class="m2m-search" placeholder="' . $escapedPlaceholder
            . '" aria-label="' . $escapedPlaceholder . '">';
    }
    if (!$readOnly) {
        $head .= '<button type="button" class="m2m-link" data-m2m-all>'
            . $escape(t('form.m2m_select_all')) . '</button>'
            . '<button type="button" class="m2m-link" data-m2m-none>' . $escape(t('form.m2m_clear')) . '</button>';
    }
    if ($head !== '') {
        $html .= '<div class="m2m-panel-head">' . $head . '</div>' . "\n";
    }

    $html .= '<div class="m2m-options">' . "\n";
    foreach ($options as $option) {
        $html .= '<label class="m2m-option">'
            . '<input type="checkbox" name="m2m_' . $index . '[]"'
            . ' value="' . $escape((string)$option['id']) . '"'
            . (in_array((string)$option['id'], $selected, true) ? ' checked' : '')
            . ($readOnly ? ' disabled' : '') . '>'
            . '<span class="m2m-option-label">' . $escape((string)$option['label']) . '</span>'
            . '</label>' . "\n";
    }
    $html .= '</div>' . "\n"
        . '<p class="m2m-no-matches" hidden>' . $escape(t('form.m2m_no_matches')) . '</p>' . "\n"
        . '</div>' . "\n</details>\n</div>\n";

    return $html;
}

function os_m2m_summary(array $labels): string
{
    if ($labels === []) {
        return '<span class="m2m-summary-empty">'
            . htmlspecialchars(t('form.m2m_none_selected'), ENT_QUOTES, 'UTF-8')
            . '</span>';
    }

    if (count($labels) > OS_M2M_SUMMARY_CHIPS + 1) {
        $shown = array_slice($labels, 0, OS_M2M_SUMMARY_CHIPS);
        $more  = count($labels) - OS_M2M_SUMMARY_CHIPS;
    } else {
        $shown = $labels;
        $more  = 0;
    }

    $html = '';
    foreach ($shown as $chipLabel) {
        $html .= '<span class="m2m-chip">' . htmlspecialchars($chipLabel, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ($more > 0) {
        $html .= '<span class="m2m-chip m2m-chip-more">+' . $more . '</span>';
    }
    return $html;
}
