<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const MATERIAL_METADATA_URL = 'https://fonts.google.com/metadata/icons?incomplete=true&key=material_symbols';
const MATERIAL_SYMBOL_FAMILY = 'Material Symbols Outlined';
const MATERIAL_PRIMARY_URL_TEMPLATE = 'https://fonts.gstatic.com/s/i/short-term/release'
    . '/materialsymbolsoutlined/%1$s/default/24px.svg';
const MATERIAL_FALLBACK_URL_TEMPLATE = 'https://raw.githubusercontent.com/google/material-design-icons'
    . '/master/symbols/web/%1$s/materialsymbolsoutlined/%1$s_24px.svg';
const MATERIAL_INTRINSIC_SIZE = '48';
const MATERIAL_MAXIMUM_SVG_BYTES = 65536;
const MATERIAL_CA_BUNDLE_CANDIDATES = [
    'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt',
    'C:\\Program Files\\Git\\mingw64\\ssl\\certs\\ca-bundle.crt',
    'C:\\php\\extras\\ssl\\cacert.pem',
    'C:\\php\\cacert.pem',
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/usr/local/etc/openssl/cert.pem',
];
const MATERIAL_ALLOWED_ELEMENTS = ['svg', 'g', 'path'];
const MATERIAL_ALLOWED_ATTRIBUTES = [
    'svg'  => ['xmlns', 'width', 'height', 'viewBox', 'fill'],
    'g'    => ['fill', 'fill-rule', 'clip-rule', 'opacity', 'transform'],
    'path' => ['d', 'fill', 'fill-rule', 'clip-rule', 'opacity', 'transform'],
];

function material_usage(): void
{
    $lines = [
        'usage: php scripts/fetch-material-icons.php [--all | --add=names | --from=file] [options]',
        '',
        '  --all            fetch every Material Symbols Outlined icon (3899 files, about 1.7 MB)',
        '  --add=NAMES      fetch a comma separated list of icon names',
        '  --from=FILE      fetch the icon names listed in FILE, one per line (# starts a comment)',
        '  --out=DIR        output directory, must sit below public/assets/icons',
        '                   (default: public/assets/icons/material)',
        '  --metadata=FILE  read the Google Fonts icon metadata from a local file instead of the network',
        '  --cainfo=FILE    certificate bundle for TLS verification, when PHP has no curl.cainfo set',
        '  --parallel=N     concurrent downloads, 1 to 32 (default: 12)',
        '  --force          re-download and overwrite icons that are already on disk',
        '  --manifest-only  rebuild index.json and tags.json from the icons already on disk',
        '',
        'The existing icons in public/assets/icons are never read, written or renamed by this script.',
    ];
    fwrite(STDERR, implode(PHP_EOL, $lines) . PHP_EOL);
}

function material_fail(string $message, int $code): never
{
    fwrite(STDERR, 'error: ' . $message . PHP_EOL);
    exit($code);
}

function material_resolve_ca_bundle(string $override): string
{
    if ($override !== '') {
        if (!is_file($override)) {
            material_fail('certificate bundle not found: ' . $override, 2);
        }
        return $override;
    }
    foreach (['CURL_CA_BUNDLE', 'SSL_CERT_FILE'] as $variable) {
        $fromEnvironment = (string) getenv($variable);
        if ($fromEnvironment !== '' && is_file($fromEnvironment)) {
            return $fromEnvironment;
        }
    }
    if ((string) ini_get('curl.cainfo') !== '' || (string) ini_get('openssl.cafile') !== '') {
        return '';
    }
    foreach (MATERIAL_CA_BUNDLE_CANDIDATES as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function material_curl_options(string $url, int $timeout, string $caBundle): array
{
    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'OpenSparrow icon fetcher',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($caBundle !== '') {
        $options[CURLOPT_CAINFO] = $caBundle;
    }

    return $options;
}

function material_http_get(string $url, int $timeout, string $caBundle): array
{
    $handle = curl_init();
    curl_setopt_array($handle, material_curl_options($url, $timeout, $caBundle));
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

function material_load_catalog(string $metadataPath, string $caBundle): array
{
    if ($metadataPath !== '') {
        $raw = @file_get_contents($metadataPath);
        if ($raw === false) {
            material_fail('cannot read metadata file: ' . $metadataPath, 3);
        }
    } else {
        fwrite(STDERR, 'reading icon metadata from fonts.google.com' . PHP_EOL);
        $response = material_http_get(MATERIAL_METADATA_URL, 120, $caBundle);
        if ($response['status'] !== 200) {
            material_fail('metadata download failed, HTTP ' . $response['status'] . ' ' . $response['error'], 3);
        }
        $raw = $response['body'];
    }

    $documentStart = strpos($raw, '{');
    if ($documentStart === false) {
        material_fail('metadata is not JSON', 3);
    }

    $decoded = json_decode(substr($raw, $documentStart), true);
    if (!is_array($decoded) || !is_array($decoded['icons'] ?? null)) {
        material_fail('metadata has no icons array', 3);
    }

    $lowercase = static fn(mixed $value): string => strtolower((string) $value);
    $catalog = [];
    foreach ($decoded['icons'] as $icon) {
        $name = (string) ($icon['name'] ?? '');
        $unsupported = is_array($icon['unsupported_families'] ?? null) ? $icon['unsupported_families'] : [];
        if ($name === '' || in_array(MATERIAL_SYMBOL_FAMILY, $unsupported, true)) {
            continue;
        }
        $catalog[$name] = [
            'popularity' => (int) ($icon['popularity'] ?? 0),
            'tags'       => array_values(array_unique(array_map($lowercase, $icon['tags'] ?? []))),
            'categories' => array_values(array_unique(array_map($lowercase, $icon['categories'] ?? []))),
        ];
    }
    ksort($catalog);

    return $catalog;
}

function material_download_batch(array $urlsByName, int $parallel, string $caBundle): array
{
    $results = [];
    $pending = $urlsByName;
    $total = count($pending);
    $active = [];
    $multiHandle = curl_multi_init();

    while ($pending !== [] || $active !== []) {
        while ($pending !== [] && count($active) < $parallel) {
            $name = (string) array_key_first($pending);
            $url = $pending[$name];
            unset($pending[$name]);
            $handle = curl_init();
            curl_setopt_array($handle, material_curl_options($url, 30, $caBundle));
            curl_multi_add_handle($multiHandle, $handle);
            $active[spl_object_id($handle)] = $name;
        }

        do {
            $multiStatus = curl_multi_exec($multiHandle, $running);
        } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

        if ($running > 0) {
            curl_multi_select($multiHandle, 1.0);
        }

        while (($message = curl_multi_info_read($multiHandle)) !== false) {
            $handle = $message['handle'];
            $identifier = spl_object_id($handle);
            $name = $active[$identifier] ?? '';
            $results[$name] = [
                'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'body'   => (string) curl_multi_getcontent($handle),
                'error'  => curl_error($handle),
            ];
            unset($active[$identifier]);
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);

            if (count($results) % 200 === 0) {
                fwrite(STDERR, '  ' . count($results) . '/' . $total . PHP_EOL);
            }
        }
    }
    curl_multi_close($multiHandle);

    return $results;
}

function material_sanitize_svg(string $body): array
{
    if ($body === '' || strlen($body) > MATERIAL_MAXIMUM_SVG_BYTES) {
        return ['svg' => null, 'error' => 'empty or oversized response', 'removed' => []];
    }

    $document = new DOMDocument();
    $previousState = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($body, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previousState);

    if (!$loaded || $document->documentElement === null) {
        return ['svg' => null, 'error' => 'not well formed XML', 'removed' => []];
    }
    if ($document->doctype !== null) {
        return ['svg' => null, 'error' => 'carries a doctype', 'removed' => []];
    }
    if (strtolower($document->documentElement->nodeName) !== 'svg') {
        return ['svg' => null, 'error' => 'root element is not svg', 'removed' => []];
    }

    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//comment() | //processing-instruction()') as $node) {
        $node->parentNode?->removeChild($node);
    }

    $removed = [];
    $paths = 0;
    foreach ($xpath->query('//*') as $element) {
        if (!$element instanceof DOMElement) {
            return ['svg' => null, 'error' => 'unexpected node type', 'removed' => []];
        }
        $elementName = strtolower($element->nodeName);
        if (!in_array($elementName, MATERIAL_ALLOWED_ELEMENTS, true)) {
            return ['svg' => null, 'error' => 'unexpected element ' . $elementName, 'removed' => []];
        }
        $allowed = MATERIAL_ALLOWED_ATTRIBUTES[$elementName] ?? [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attributeName = $attribute->nodeName;
            $comparable = strtolower($attributeName);
            if (str_starts_with($comparable, 'on') || $comparable === 'href' || $comparable === 'xlink:href') {
                return ['svg' => null, 'error' => 'dangerous attribute ' . $attributeName, 'removed' => []];
            }
            if (!in_array($attributeName, $allowed, true)) {
                $element->removeAttribute($attributeName);
                $removed[] = $elementName . '/' . $attributeName;
            }
        }
        if ($elementName === 'path' && $element->getAttribute('d') !== '') {
            $paths++;
        }
    }

    if ($paths === 0) {
        return ['svg' => null, 'error' => 'no drawable path', 'removed' => []];
    }

    $root = $document->documentElement;
    if ($root->getAttribute('viewBox') === '') {
        return ['svg' => null, 'error' => 'missing viewBox', 'removed' => []];
    }
    $root->setAttribute('width', MATERIAL_INTRINSIC_SIZE);
    $root->setAttribute('height', MATERIAL_INTRINSIC_SIZE);

    $serialized = $document->saveXML($root);
    if (!is_string($serialized) || $serialized === '') {
        return ['svg' => null, 'error' => 'serialization failed', 'removed' => []];
    }

    return ['svg' => $serialized . "\n", 'error' => '', 'removed' => $removed];
}

function material_write_atomic(string $path, string $contents): bool
{
    if ($contents === '') {
        return false;
    }
    $temporaryPath = $path . '.tmp';
    $written = @file_put_contents($temporaryPath, $contents, LOCK_EX);
    if ($written !== strlen($contents)) {
        @unlink($temporaryPath);
        return false;
    }
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }

    return true;
}

function material_write_notice(string $outputDirectory): void
{
    $lines = [
        'Material Symbols icons - third-party files, not OpenSparrow source',
        '==================================================================',
        '',
        'Origin:    Google Fonts, Material Symbols Outlined, optical size 24',
        '           https://fonts.google.com/icons',
        'Copyright: Copyright (C) Google LLC',
        'License:   Apache License, Version 2.0',
        '           Full text: licenses/Apache-2.0.txt in the OpenSparrow repository',
        '',
        'MODIFICATIONS',
        '-------------',
        'Every .svg file in this directory has been changed from its upstream form in',
        'exactly one way: the width and height attributes on the root <svg> element are',
        'set to 48. The viewBox and every path are byte-for-byte the upstream ones, so',
        'the artwork itself is unmodified. The size is normalised so these files carry',
        'the same intrinsic size as the PNG icons in the parent directory, which markup',
        'without an explicit CSS size relies on.',
        '',
        'index.json and tags.json are not Google files. They are generated from the icon',
        'metadata published at https://fonts.google.com/metadata/icons.',
        '',
        'This directory is written by scripts/fetch-material-icons.php. Do not edit the',
        'files here by hand - re-run the script instead. This notice is rewritten every',
        'time the script runs.',
        '',
    ];
    if (!material_write_atomic($outputDirectory . '/README.txt', implode(PHP_EOL, $lines))) {
        material_fail('cannot write README.txt', 5);
    }
}

function material_build_manifests(string $outputDirectory, array $catalog): array
{
    material_write_notice($outputDirectory);

    $files = glob($outputDirectory . '/*.svg');
    if ($files === false) {
        $files = [];
    }
    sort($files);

    $index = [];
    $tags = [];
    $unlisted = [];
    foreach ($files as $file) {
        $name = basename($file, '.svg');
        $entry = $catalog[$name] ?? null;
        if ($entry === null) {
            $unlisted[] = $name;
            continue;
        }
        $index[] = ['name' => $name, 'popularity' => $entry['popularity']];
        $tags[] = ['name' => $name, 'tags' => $entry['tags'], 'categories' => $entry['categories']];
    }

    $generated = gmdate('c');
    $indexJson = json_encode([
        'generated' => $generated,
        'family'    => MATERIAL_SYMBOL_FAMILY,
        'count'     => count($index),
        'icons'     => $index,
    ], JSON_UNESCAPED_SLASHES);
    $tagsJson = json_encode([
        'generated' => $generated,
        'count'     => count($tags),
        'icons'     => $tags,
    ], JSON_UNESCAPED_SLASHES);

    if (!is_string($indexJson) || !is_string($tagsJson)) {
        material_fail('cannot encode the manifests', 5);
    }
    if (!material_write_atomic($outputDirectory . '/index.json', $indexJson)) {
        material_fail('cannot write index.json', 5);
    }
    if (!material_write_atomic($outputDirectory . '/tags.json', $tagsJson)) {
        material_fail('cannot write tags.json', 5);
    }

    return ['count' => count($index), 'unlisted' => $unlisted];
}

function material_read_name_file(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        material_fail('cannot read name file: ' . $path, 2);
    }
    $names = [];
    foreach ($lines as $line) {
        $name = trim(explode('#', $line, 2)[0]);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

$options = getopt('', [
    'all', 'add:', 'from:', 'out:', 'metadata:', 'cainfo:', 'parallel:', 'force', 'manifest-only', 'help',
]);
if (!is_array($options)) {
    material_usage();
    exit(2);
}
if (isset($options['help'])) {
    material_usage();
    exit(0);
}

$manifestOnly = isset($options['manifest-only']);
$force = isset($options['force']);
$fetchAll = isset($options['all']);
if (!$fetchAll && !$manifestOnly && !isset($options['add']) && !isset($options['from'])) {
    material_usage();
    exit(2);
}

$parallel = isset($options['parallel']) ? (int) $options['parallel'] : 12;
if ($parallel < 1 || $parallel > 32) {
    material_fail('--parallel must be between 1 and 32', 2);
}

$projectRoot = dirname(__DIR__);
$iconsRoot = $projectRoot . '/public/assets/icons';
$resolvedIconsRoot = realpath($iconsRoot);
if ($resolvedIconsRoot === false) {
    material_fail('cannot resolve ' . $iconsRoot, 4);
}

$outputDirectory = isset($options['out']) ? (string) $options['out'] : $iconsRoot . '/material';
if (!is_dir($outputDirectory) && !@mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    material_fail('cannot create output directory: ' . $outputDirectory, 4);
}
$resolvedOutput = realpath($outputDirectory);
if ($resolvedOutput === false) {
    material_fail('cannot resolve output directory: ' . $outputDirectory, 4);
}
if ($resolvedOutput === $resolvedIconsRoot) {
    material_fail('refusing to write into ' . $resolvedIconsRoot . ', the existing icon set stays untouched', 4);
}
if (!str_starts_with($resolvedOutput, $resolvedIconsRoot . DIRECTORY_SEPARATOR)) {
    material_fail('output directory must sit below ' . $resolvedIconsRoot, 4);
}

$caBundle = material_resolve_ca_bundle(isset($options['cainfo']) ? (string) $options['cainfo'] : '');
if ($caBundle !== '') {
    fwrite(STDERR, 'certificate bundle: ' . $caBundle . PHP_EOL);
} elseif ((string) ini_get('curl.cainfo') === '' && (string) ini_get('openssl.cafile') === '') {
    fwrite(STDERR, 'warning: no certificate bundle found, TLS verification may fail;'
        . ' set curl.cainfo in php.ini or pass --cainfo=FILE' . PHP_EOL);
}

$catalog = material_load_catalog(isset($options['metadata']) ? (string) $options['metadata'] : '', $caBundle);
fwrite(STDERR, 'catalog: ' . count($catalog) . ' icons in ' . MATERIAL_SYMBOL_FAMILY . PHP_EOL);

if ($manifestOnly) {
    $manifest = material_build_manifests($resolvedOutput, $catalog);
    fwrite(STDERR, 'manifests rebuilt for ' . $manifest['count'] . ' icons' . PHP_EOL);
    if ($manifest['unlisted'] !== []) {
        fwrite(STDERR, 'on disk but not in catalog: ' . implode(', ', $manifest['unlisted']) . PHP_EOL);
    }
    exit(0);
}

$requested = [];
if ($fetchAll) {
    $requested = array_keys($catalog);
}
if (isset($options['add'])) {
    $requested = array_merge($requested, array_map('trim', explode(',', (string) $options['add'])));
}
if (isset($options['from'])) {
    $requested = array_merge($requested, material_read_name_file((string) $options['from']));
}

$malformed = [];
$unknown = [];
$selected = [];
foreach (array_values(array_unique($requested)) as $requestedName) {
    $name = trim((string) $requestedName);
    if ($name === '') {
        continue;
    }
    if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
        $malformed[] = $name;
        continue;
    }
    if (!isset($catalog[$name])) {
        $unknown[] = $name;
        continue;
    }
    $selected[] = $name;
}

foreach ($malformed as $name) {
    fwrite(STDERR, 'skipped, not a valid icon name: ' . $name . PHP_EOL);
}
foreach ($unknown as $name) {
    fwrite(STDERR, 'skipped, not in ' . MATERIAL_SYMBOL_FAMILY . ': ' . $name . PHP_EOL);
}

$queue = [];
$alreadyPresent = 0;
foreach ($selected as $name) {
    if (!$force && is_file($resolvedOutput . DIRECTORY_SEPARATOR . $name . '.svg')) {
        $alreadyPresent++;
        continue;
    }
    $queue[$name] = sprintf(MATERIAL_PRIMARY_URL_TEMPLATE, $name);
}

$written = 0;
$sanitized = [];
$failed = [];

if ($queue !== []) {
    fwrite(STDERR, 'downloading ' . count($queue) . ' icons with ' . $parallel . ' connections' . PHP_EOL);
    $responses = material_download_batch($queue, $parallel, $caBundle);

    $retry = [];
    foreach ($queue as $name => $url) {
        if ((int) ($responses[$name]['status'] ?? 0) !== 200) {
            $retry[$name] = sprintf(MATERIAL_FALLBACK_URL_TEMPLATE, $name);
        }
    }
    if ($retry !== []) {
        fwrite(STDERR, 'retrying ' . count($retry) . ' icons from raw.githubusercontent.com' . PHP_EOL);
        foreach (material_download_batch($retry, $parallel, $caBundle) as $name => $response) {
            $responses[$name] = $response;
        }
    }

    foreach ($queue as $name => $url) {
        $response = $responses[$name] ?? ['status' => 0, 'body' => '', 'error' => 'no response'];
        if ((int) $response['status'] !== 200) {
            $failed[$name] = 'HTTP ' . $response['status'] . ' ' . $response['error'];
            continue;
        }
        $result = material_sanitize_svg((string) $response['body']);
        if ($result['svg'] === null) {
            $failed[$name] = $result['error'];
            continue;
        }
        if ($result['removed'] !== []) {
            $sanitized[$name] = array_values(array_unique($result['removed']));
        }
        if (!material_write_atomic($resolvedOutput . DIRECTORY_SEPARATOR . $name . '.svg', $result['svg'])) {
            $failed[$name] = 'write failed';
            continue;
        }
        $written++;
    }
}

$manifest = material_build_manifests($resolvedOutput, $catalog);

fwrite(STDERR, PHP_EOL);
fwrite(STDERR, 'written:       ' . $written . PHP_EOL);
fwrite(STDERR, 'already there: ' . $alreadyPresent . PHP_EOL);
fwrite(STDERR, 'in manifests:  ' . $manifest['count'] . PHP_EOL);
fwrite(STDERR, 'output:        ' . $resolvedOutput . PHP_EOL);

foreach ($sanitized as $name => $attributes) {
    fwrite(STDERR, 'stripped attributes in ' . $name . ': ' . implode(', ', $attributes) . PHP_EOL);
}
if ($manifest['unlisted'] !== []) {
    fwrite(STDERR, 'on disk but not in catalog: ' . implode(', ', $manifest['unlisted']) . PHP_EOL);
}
foreach ($failed as $name => $reason) {
    fwrite(STDERR, 'FAILED ' . $name . ': ' . $reason . PHP_EOL);
}

exit($failed === [] ? 0 : 1);
