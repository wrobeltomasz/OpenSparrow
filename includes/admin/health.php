<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

if ($action === 'health') {
    $db_connected = false;
    $db_error = 'Unknown error';
    $pg_version = null;
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        if ($conn) {
            $db_connected = true;
            $db_error = '';
            $versionResult = @pg_query($conn, 'SELECT version()');
            if ($versionResult) {
                $row = pg_fetch_row($versionResult);

                if (preg_match('/PostgreSQL\s+([\d.]+)/i', $row[0] ?? '', $matches)) {
                    $pg_version = $matches[1];
                }
            }
        }
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        $db_error = $exception->getMessage();
    }

    $versionFile = __DIR__ . '/../../includes/VERSION';
    $appVersion = file_exists($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown';

    $displayErrors = ini_get('display_errors');

    $data = [
        'app_version'      => $appVersion,

        'php_version'      => PHP_VERSION,
        'php_version_ok'   => version_compare(PHP_VERSION, '8.4.0', '>='),
        'memory_limit'     => ini_get('memory_limit'),
        'memory_limit_ok'  => (int) ini_get('memory_limit') >= 64 || ini_get('memory_limit') === '-1',
        'upload_max_filesize'    => ini_get('upload_max_filesize'),
        'upload_max_filesize_ok' => (int) ini_get('upload_max_filesize') >= 8,
        'display_errors_off'     => $displayErrors === '' || $displayErrors == '0'
            || strtolower((string) $displayErrors) === 'off',

        'pgsql_ok'     => extension_loaded('pgsql') || extension_loaded('pdo_pgsql'),
        'json_ok'      => extension_loaded('json'),
        'session_ok'   => extension_loaded('session'),
        'mbstring_ok'  => extension_loaded('mbstring'),
        'fileinfo_ok'  => extension_loaded('fileinfo'),
        'openssl_ok'   => extension_loaded('openssl'),

        'argon2id_ok'      => defined('PASSWORD_ARGON2ID'),
        'random_bytes_ok'  => function_exists('random_bytes'),
        'hash_equals_ok'   => function_exists('hash_equals'),
        'bin2hex_ok'       => function_exists('bin2hex'),

        'db_connected'       => $db_connected,
        'db_error'           => $db_error,
        'pg_version'         => $pg_version,

        'dir_writable'          => is_writable(__DIR__ . '/../../config'),
        'storage_writable'      => is_dir(os_storage_path()) && is_writable(os_storage_path()),
        'storage_files_writable' => is_dir(os_storage_path('files'))
            && is_writable(os_storage_path('files')),

        'database_json_ok' => (static function () {
            $configPath = __DIR__ . '/../../config/database.json';
            return file_exists($configPath) && is_array(json_decode((string) @file_get_contents($configPath), true));
        })(),
        'schema_json_ok' => (static function () {
            require_once __DIR__ . '/../config_store.php';
            return is_array(config_get('schema'));
        })(),
    ];
    throw ResponseException::encoded($data);
}

if ($action === 'health_production') {
    $forwardedProtocol = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        || $forwardedProtocol === 'https'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
        || strtolower((string) ($_SERVER['HTTP_CF_VISITOR'] ?? '')) !== '' && str_contains(
            strtolower((string) $_SERVER['HTTP_CF_VISITOR']),
            '"scheme":"https"'
        );

    $displayErrors = ini_get('display_errors');

    $data = [
        'app_env'             => defined('APP_ENV') ? APP_ENV : 'unknown',
        'demo_mode_off'       => defined('DEMO_MODE') && DEMO_MODE === false,
        'secure_cookies_on'   => defined('SECURE_COOKIES') && SECURE_COOKIES === true,
        'https_detected'      => $https,
        'display_errors_off'  => $displayErrors === '' || $displayErrors == '0'
            || strtolower((string) $displayErrors) === 'off',
        'argon2id_ok'         => defined('PASSWORD_ARGON2ID'),
        'password_min_length' => defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : null,
        'password_min_length_ok' => defined('PASSWORD_MIN_LENGTH') && PASSWORD_MIN_LENGTH >= 12,
        'rate_limit_per_min'  => defined('API_RATE_LIMIT_PER_MIN') ? API_RATE_LIMIT_PER_MIN : null,
        'rate_limit_on'       => defined('API_RATE_LIMIT_PER_MIN') && API_RATE_LIMIT_PER_MIN > 0,
        'session_samesite'    => defined('SESSION_SAMESITE') ? SESSION_SAMESITE : null,
        'trust_proxy_headers' => defined('TRUST_PROXY_HEADERS') && TRUST_PROXY_HEADERS === true,
        'trusted_proxy_ips_set' => defined('TRUSTED_PROXY_IPS') && TRUSTED_PROXY_IPS !== [],
    ];
    throw ResponseException::encoded($data);
}
