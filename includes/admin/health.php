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
            $vr = @pg_query($conn, 'SELECT version()');
            if ($vr) {
                $row = pg_fetch_row($vr);

                if (preg_match('/PostgreSQL\s+([\d.]+)/i', $row[0] ?? '', $m)) {
                    $pg_version = $m[1];
                }
            }
        }
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }

    $versionFile = __DIR__ . '/../../includes/VERSION';
    $appVersion = file_exists($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown';

    $displayErrors = ini_get('display_errors');

    $data = [
        'app_version'      => $appVersion,

        'php_version'      => PHP_VERSION,
        'php_version_ok'   => version_compare(PHP_VERSION, '8.1.0', '>='),
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
        'storage_writable'      => is_dir(__DIR__ . '/../../storage') && is_writable(__DIR__ . '/../../storage'),
        'storage_files_writable' => is_dir(__DIR__ . '/../../storage/files')
            && is_writable(__DIR__ . '/../../storage/files'),

        'database_json_ok' => (static function () {
            $f = __DIR__ . '/../../config/database.json';
            return file_exists($f) && is_array(@json_decode(@file_get_contents($f), true));
        })(),
        'schema_json_ok' => (static function () {
            require_once __DIR__ . '/../config_store.php';
            return is_array(config_get('schema'));
        })(),
        'security_json_ok' => (static function () {
            $f = __DIR__ . '/../../config/security.json';
            return file_exists($f) && is_array(@json_decode(@file_get_contents($f), true));
        })(),
    ];
    throw ResponseException::encoded($data);
}
