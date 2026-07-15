<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// page_helpers.php — Shared HTML fragments for frontend page controllers
// os_header_search()        — header search pill (search & filter UI standard)
// os_header_filters()       — header filter-chip container
// os_header_clear_filters() — the #clearFilters button (last header control on every page)
// os_inline_globals()       — nonce'd <script> exposing window.* globals (JSON_HEX_* hardened)
// os_module_script()        — nonce'd <script type="module"> tag with ?v=filemtime cache busting
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
