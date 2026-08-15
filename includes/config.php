<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

if (!function_exists('get_env')) {
    function get_env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        if (defined('TRUST_PROXY_HEADERS') && !TRUST_PROXY_HEADERS) {
            return $_SERVER['REMOTE_ADDR'] ?? '';
        }
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && !empty($_SERVER['HTTP_CF_RAY'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}

if (!function_exists('settings_value')) {
    function settings_value(string $key, mixed $default = null): mixed
    {
        static $settings = null;
        if ($settings === null) {
            $settings = [];
            try {
                require_once __DIR__ . '/config_store.php';
                $decoded = config_get('settings');
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            } catch (\App\Exception\ControlFlowException $signal) {
                throw $signal;
            } catch (Throwable $e) {
            }
        }
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }
}

if (!function_exists('os_last_error_reason')) {
    function os_last_error_reason(): string
    {
        $last = error_get_last();
        return isset($last['message']) ? ': ' . $last['message'] : '';
    }
}

if (!function_exists('os_ensure_directory')) {
    function os_ensure_directory(string $dir, int $mode): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            error_log('[storage] directory could not be created: ' . $dir . os_last_error_reason());
            return false;
        }
        return true;
    }
}

if (!function_exists('os_write_guard_file')) {
    function os_write_guard_file(string $path, string $contents): bool
    {
        if (is_file($path)) {
            return true;
        }
        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            error_log(
                '[security] web access guard file could not be written: ' . $path . os_last_error_reason()
            );
            return false;
        }
        return true;
    }
}

if (!function_exists('os_persist_secret')) {
    function os_persist_secret(string $file, string $secret, string $label): bool
    {
        if (@file_put_contents($file, $secret, LOCK_EX) === false) {
            error_log(
                '[security] ' . $label . ' could not be persisted to ' . $file
                . ' - a new value is generated on every request until this is fixed'
                . os_last_error_reason()
            );
            return false;
        }
        if (!@chmod($file, 0600)) {
            error_log(
                '[security] ' . $label . ' file kept its default permissions: ' . $file . os_last_error_reason()
            );
        }
        return true;
    }
}

if (defined('OPENSPARROW_CONFIG_LOADED')) {
    return;
}
define('OPENSPARROW_CONFIG_LOADED', true);
require_once __DIR__ . '/version.php';

if (
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
    (!empty($_SERVER['HTTP_CF_VISITOR']) && stripos($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false) ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}

$_projectRoot = realpath(__DIR__ . '/..');
$_envSessPath = get_env('SESSION_SAVE_PATH', '');
if ($_envSessPath !== '') {
    ini_set('session.save_path', $_envSessPath);
} elseif ($_projectRoot !== false) {
    $_absPath = $_projectRoot . '/storage/sessions';
    os_ensure_directory($_absPath, 0700);
    if (is_dir($_absPath) && is_writable($_absPath)) {
        ini_set('session.save_path', $_absPath);
        os_write_guard_file($_absPath . '/.htaccess', "Require all denied\n");
    }
    unset($_absPath);
}
unset($_projectRoot, $_envSessPath);

$_projectRoot = realpath(__DIR__ . '/..');
$_envTmpPath  = get_env('SYS_TEMP_DIR', '');
if ($_envTmpPath !== '') {
    ini_set('sys_temp_dir', $_envTmpPath);
    putenv('TMPDIR=' . $_envTmpPath);
} elseif ($_projectRoot !== false) {
    $_absTmp = $_projectRoot . '/storage/tmp';
    os_ensure_directory($_absTmp, 0700);
    if (is_dir($_absTmp) && is_writable($_absTmp)) {
        ini_set('sys_temp_dir', $_absTmp);
        putenv('TMPDIR=' . $_absTmp);
        os_write_guard_file($_absTmp . '/.htaccess', "Require all denied\n");
    }
    unset($_absTmp);
}
unset($_projectRoot, $_envTmpPath);

define('APP_ENV', get_env('APP_ENV', 'production'));

define('DB_HOST', get_env('DB_HOST', get_env('PGHOST', 'localhost')));

define('DB_PORT', get_env('DB_PORT', get_env('PGPORT', '5432')));

define('DB_CONNECT_TIMEOUT', (int) get_env('DB_CONNECT_TIMEOUT', '5'));

define('APP_TIMEZONE', get_env('APP_TIMEZONE', 'Europe/Warsaw'));

define('SECURE_COOKIES', get_env('SECURE_COOKIES', 'true') === 'true');

define('SESSION_SAMESITE', get_env('SESSION_SAMESITE', 'Lax'));

define('SESSION_MAX_LIFETIME', (int) get_env('SESSION_MAX_LIFETIME', '28800'));

ini_set('session.gc_maxlifetime', (string) SESSION_MAX_LIFETIME);

(static function (): void {
    $env = get_env('IP_HASH_SALT', '');
    if ($env !== '') {
        define('IP_HASH_SALT', $env);
        return;
    }
    $file = __DIR__ . '/.secret_salt';
    $stored = is_file($file) ? trim((string) @file_get_contents($file)) : '';
    if ($stored === '') {
        $stored = bin2hex(random_bytes(32));
        os_persist_secret($file, $stored, 'IP_HASH_SALT');
    }
    define('IP_HASH_SALT', $stored);
})();

(static function (): void {
    $env = get_env('APP_ENCRYPTION_KEY', '');
    if ($env !== '') {
        define('APP_ENCRYPTION_KEY', $env);
        return;
    }
    $file = __DIR__ . '/.secret_key';
    $stored = is_file($file) ? trim((string) @file_get_contents($file)) : '';
    if ($stored === '') {
        $stored = bin2hex(random_bytes(32));
        os_persist_secret($file, $stored, 'APP_ENCRYPTION_KEY');
    }
    define('APP_ENCRYPTION_KEY', $stored);
})();
require_once __DIR__ . '/crypto.php';

define('ARGON2_OPTIONS', ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1]);

define('LOGIN_MAX_ATTEMPTS_PER_IP', (int) get_env('LOGIN_MAX_ATTEMPTS_PER_IP', '20'));

define('LOGIN_MAX_ATTEMPTS_PER_USERNAME', (int) get_env('LOGIN_MAX_ATTEMPTS_PER_USERNAME', '5'));

define('LOGIN_LOCKOUT_MINUTES', (int) get_env('LOGIN_LOCKOUT_MINUTES', '15'));

define('TRUST_PROXY_HEADERS', get_env('TRUST_PROXY_HEADERS', 'true') === 'true');

define('DEMO_MODE', get_env('DEMO_MODE', 'false') === 'true');

define('FILES_MAX_SIZE_MB', (int) get_env('FILES_MAX_SIZE_MB', '20'));

define('FILES_PAGE_LIMIT', (int) get_env('FILES_PAGE_LIMIT', '25'));

define('FILES_PAGE_LIMIT_MAX', (int) get_env('FILES_PAGE_LIMIT_MAX', '100'));

define('THUMBNAIL_MAX_WIDTH', (int) get_env('THUMBNAIL_MAX_WIDTH', '300'));

define('FILE_CACHE_MAX_AGE', (int) get_env('FILE_CACHE_MAX_AGE', '3600'));

define('THUMBNAIL_CACHE_MAX_AGE', (int) get_env('THUMBNAIL_CACHE_MAX_AGE', '86400'));

define('COMMENTS_PAGE_LIMIT_MAX', (int) get_env('COMMENTS_PAGE_LIMIT_MAX', '50'));

define('COMMENTS_MINE_LIMIT', (int) get_env('COMMENTS_MINE_LIMIT', '50'));

define('NOTIFICATIONS_DROPDOWN_LIMIT', (int) get_env('NOTIFICATIONS_DROPDOWN_LIMIT', '10'));

define('AUTOMATION_EMAIL_FROM', (function (): string {
    $env = get_env('AUTOMATION_EMAIL_FROM', '');
    return $env !== '' ? $env : (string) settings_value('automation_email_from', '');
})());

define('AUTOMATION_EMAIL_BATCH_LIMIT', (int) get_env('AUTOMATION_EMAIL_BATCH_LIMIT', '50'));

define('AUTOMATION_EMAIL_MAX_ATTEMPTS', (int) get_env('AUTOMATION_EMAIL_MAX_ATTEMPTS', '3'));

define('CONFIG_FILE_MAX_BYTES', (int) get_env('CONFIG_FILE_MAX_BYTES', '524288'));

define('MAX_LIST_ROWS', (int) get_env('MAX_LIST_ROWS', '10000'));

define('HSTS_MAX_AGE', (int) get_env('HSTS_MAX_AGE', '31536000'));

define('RECORD_SNAPSHOTS_ENABLED', (function (): bool {
    $env = get_env('RECORD_SNAPSHOTS_ENABLED', '');
    if ($env !== '') {
        return $env === 'true';
    }
    return (bool) settings_value('record_snapshots_enabled', false);
})());

define('CHAT_BUBBLE_ENABLED', (function (): bool {
    $env = get_env('CHAT_BUBBLE_ENABLED', '');
    if ($env !== '') {
        return $env === 'true';
    }
    return (bool) settings_value('chat_bubble_enabled', false);
})());

define('OLLAMA_URL', get_env('OLLAMA_URL', 'http://localhost:11434'));

define('OLLAMA_MODEL', get_env('OLLAMA_MODEL', 'llama3'));

define('RAG_RATE_LIMIT_PER_MIN', (int) get_env('RAG_RATE_LIMIT_PER_MIN', '10'));

define('RAG_MAX_CONCURRENT', (int) get_env('RAG_MAX_CONCURRENT', '2'));

define('RAG_PAGE_CONTEXT_MAX_CHARS', (int) get_env('RAG_PAGE_CONTEXT_MAX_CHARS', '12000'));
