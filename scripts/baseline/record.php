<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['base:', 'out:', 'only::', 'seed::']);

if (!isset($options['base'], $options['out'])) {
    fwrite(STDERR, "usage: php scripts/baseline/record.php --base=http://127.0.0.1:8080"
        . " --out=snapshot.json [--only=prefix] [--seed=1]\n");
    exit(2);
}

$baseUrl    = rtrim((string) $options['base'], '/');
$outputPath = (string) $options['out'];
$onlyPrefix = isset($options['only']) ? (string) $options['only'] : '';
$scenarios  = require __DIR__ . '/scenarios.php';

$cookieDirectory = sys_get_temp_dir() . '/opensparrow-baseline-' . getmypid();
if (!is_dir($cookieDirectory) && !mkdir($cookieDirectory, 0777, true) && !is_dir($cookieDirectory)) {
    fwrite(STDERR, "cannot create cookie directory\n");
    exit(3);
}

function baseline_request(
    string $url,
    string $cookieJar,
    string $method = 'GET',
    ?string $payload = null,
    array $headers = []
): array {
    $handle = curl_init();
    curl_setopt_array($handle, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
    }

    $response = curl_exec($handle);
    if ($response === false) {
        $error = curl_error($handle);
        curl_close($handle);
        return ['status' => 0, 'headers' => '', 'body' => 'CURL ERROR: ' . $error];
    }

    $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    return [
        'status'  => $status,
        'headers' => substr((string) $response, 0, $headerSize),
        'body'    => substr((string) $response, $headerSize),
    ];
}

function baseline_login(string $baseUrl, string $cookieJar, string $username, string $password): bool
{
    $form = baseline_request($baseUrl . '/login.php', $cookieJar);
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $form['body'], $matches) !== 1) {
        return false;
    }

    $result = baseline_request(
        $baseUrl . '/login.php',
        $cookieJar,
        'POST',
        http_build_query([
            'csrf_token' => $matches[1],
            'username'   => $username,
            'password'   => $password,
        ]),
        ['Content-Type: application/x-www-form-urlencoded']
    );

    return in_array($result['status'], [200, 302, 303], true)
        && !str_contains($result['body'], 'name="password"');
}

function baseline_csrf_token(string $baseUrl, string $cookieJar): string
{
    $patterns = [
        '/window\.CSRF_TOKEN\s*=\s*"([^"]+)"/',
        '/"CSRF_TOKEN"\s*:\s*"([^"]+)"/',
        '/name="csrf-token"\s+content="([^"]+)"/',
    ];

    foreach (['/files.php', '/admin/index.php'] as $path) {
        $page = baseline_request($baseUrl . $path, $cookieJar);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $page['body'], $matches) === 1) {
                return $matches[1];
            }
        }
    }
    return '';
}

function baseline_normalize(string $body, array $volatile): string
{
    $body    = str_replace("\r\n", "\n", $body);
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        ksort($decoded);
        $body = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    foreach ($volatile as $pattern => $replacement) {
        $body = (string) preg_replace($pattern, $replacement, $body);
    }
    return trim($body);
}

$snapshot = [
    'base'      => $baseUrl,
    'recorded'  => gmdate('c'),
    'scenarios' => [],
];

if (isset($options['seed'])) {
    $seed = baseline_request($baseUrl . '/cypress_seed.php?action=seed', $cookieDirectory . '/seed.txt', 'POST');
    fwrite(STDOUT, 'seed: HTTP ' . $seed['status'] . "\n");
}

foreach ($scenarios['roles'] as $role => $credentials) {
    $cookieJar = $cookieDirectory . '/' . $role . '.txt';
    $token     = '';

    if ($credentials !== null) {
        if (!baseline_login($baseUrl, $cookieJar, $credentials[0], $credentials[1])) {
            fwrite(STDERR, "login failed for role {$role} — run with --seed=1 first\n");
            exit(4);
        }
        $token = baseline_csrf_token($baseUrl, $cookieJar);
        if ($token === '') {
            fwrite(STDERR, "no CSRF token found for role {$role} — every write scenario would "
                . "record a 403 that proves nothing\n");
            exit(5);
        }
    }

    foreach ($scenarios['cases'] as $case) {
        $id = $role . ' ' . $case['id'];
        if ($onlyPrefix !== '' && !str_starts_with($case['id'], $onlyPrefix)) {
            continue;
        }

        $method  = $case['method'] ?? 'GET';
        $headers = [];
        $payload = null;

        if (!empty($case['ajax'])) {
            $headers[] = 'X-Requested-With: XMLHttpRequest';
        }
        $withCsrf = $method !== 'GET' && ($case['csrf'] ?? true) && $token !== '';

        if (isset($case['json'])) {
            $json = $case['json'];
            if ($withCsrf) {
                $json['csrf_token'] = $token;
            }
            $payload   = (string) json_encode($json);
            $headers[] = 'Content-Type: application/json';
        }
        if ($withCsrf) {
            $headers[] = 'X-CSRF-Token: ' . $token;
        }

        $response = baseline_request($baseUrl . '/' . $case['path'], $cookieJar, $method, $payload, $headers);

        preg_match('/^content-type:\s*(.+)$/im', $response['headers'], $contentType);
        preg_match('/^location:\s*(.+)$/im', $response['headers'], $location);

        $snapshot['scenarios'][$id] = [
            'status'      => $response['status'],
            'contentType' => isset($contentType[1]) ? trim($contentType[1]) : '',
            'location'    => isset($location[1]) ? trim($location[1]) : '',
            'body'        => baseline_normalize($response['body'], $scenarios['volatile']),
        ];

        fwrite(STDOUT, sprintf("%-52s %3d\n", $id, $response['status']));
    }
}

ksort($snapshot['scenarios']);
file_put_contents(
    $outputPath,
    (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

foreach (glob($cookieDirectory . '/*.txt') ?: [] as $jar) {
    unlink($jar);
}
rmdir($cookieDirectory);

fwrite(STDOUT, count($snapshot['scenarios']) . " scenarios written to {$outputPath}\n");
