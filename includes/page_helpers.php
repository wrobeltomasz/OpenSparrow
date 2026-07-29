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
// os_module_graph()         — one shared ?v= + import map for a whole ES module tree
// os_import_map()           — renders that map; must precede the first module script
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
