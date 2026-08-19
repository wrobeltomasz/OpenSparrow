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
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }
}

if (!function_exists('os_ip_matches_cidr')) {
    function os_ip_matches_cidr(string $ip, string $range): bool
    {
        $range = trim($range);
        if ($range === '') {
            return false;
        }
        if (strpos($range, '/') === false) {
            $binaryLiteral = @inet_pton($range);
            $binaryClient  = @inet_pton($ip);
            return $binaryLiteral !== false && $binaryClient !== false && $binaryClient === $binaryLiteral;
        }
        [$subnet, $prefixText] = explode('/', $range, 2);
        $binaryClient = @inet_pton($ip);
        $binarySubnet = @inet_pton(trim($subnet));
        if (
            $binaryClient === false || $binarySubnet === false
            || strlen($binaryClient) !== strlen($binarySubnet)
        ) {
            return false;
        }
        $prefixLength = (int) $prefixText;
        if ($prefixLength < 0 || $prefixLength > strlen($binaryClient) * 8) {
            return false;
        }
        $wholeBytes = intdiv($prefixLength, 8);
        if (
            $wholeBytes > 0
            && substr($binaryClient, 0, $wholeBytes) !== substr($binarySubnet, 0, $wholeBytes)
        ) {
            return false;
        }
        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;
        return (ord($binaryClient[$wholeBytes]) & $mask) === (ord($binarySubnet[$wholeBytes]) & $mask);
    }
}

if (!function_exists('os_is_trusted_proxy')) {
    function os_is_trusted_proxy(string $remoteAddress): bool
    {
        if (!defined('TRUSTED_PROXY_IPS') || TRUSTED_PROXY_IPS === []) {
            return true;
        }
        if ($remoteAddress === '') {
            return false;
        }
        foreach (TRUSTED_PROXY_IPS as $range) {
            if (os_ip_matches_cidr($remoteAddress, $range)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('os_first_forwarded_ip')) {
    function os_first_forwarded_ip(string $headerValue): string
    {
        foreach (explode(',', $headerValue) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('os_forwarded_ip')) {
    function os_forwarded_ip(string $source): string
    {
        return match ($source) {
            'cf' => !empty($_SERVER['HTTP_CF_CONNECTING_IP']) && !empty($_SERVER['HTTP_CF_RAY'])
                ? os_first_forwarded_ip((string) $_SERVER['HTTP_CF_CONNECTING_IP'])
                : '',
            'x-real-ip'       => os_first_forwarded_ip((string) ($_SERVER['HTTP_X_REAL_IP'] ?? '')),
            'x-forwarded-for' => os_first_forwarded_ip((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')),
            default           => '',
        };
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!defined('TRUST_PROXY_HEADERS') || !TRUST_PROXY_HEADERS) {
            return $remoteAddress;
        }
        if (!os_is_trusted_proxy($remoteAddress)) {
            return $remoteAddress;
        }
        $sources = defined('FORWARDED_HEADER_PRIORITY') ? FORWARDED_HEADER_PRIORITY : [];
        foreach ($sources as $source) {
            $forwarded = os_forwarded_ip($source);
            if ($forwarded !== '') {
                return $forwarded;
            }
        }
        return $remoteAddress;
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
            } catch (Throwable $exception) {
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
    function os_ensure_directory(string $directory, int $mode): bool
    {
        if (is_dir($directory)) {
            return true;
        }
        if (!@mkdir($directory, $mode, true) && !is_dir($directory)) {
            error_log('[storage] directory could not be created: ' . $directory . os_last_error_reason());
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
$environmentSessPath = get_env('SESSION_SAVE_PATH', '');
if ($environmentSessPath !== '') {
    ini_set('session.save_path', $environmentSessPath);
} elseif ($_projectRoot !== false) {
    $_absPath = $_projectRoot . '/storage/sessions';
    os_ensure_directory($_absPath, 0700);
    if (is_dir($_absPath) && is_writable($_absPath)) {
        ini_set('session.save_path', $_absPath);
        os_write_guard_file($_absPath . '/.htaccess', "Require all denied\n");
    }
    unset($_absPath);
}
unset($_projectRoot, $environmentSessPath);

$_projectRoot = realpath(__DIR__ . '/..');
$environmentTemporaryPath  = get_env('SYS_TEMP_DIR', '');
if ($environmentTemporaryPath !== '') {
    ini_set('sys_temp_dir', $environmentTemporaryPath);
    putenv('TMPDIR=' . $environmentTemporaryPath);
} elseif ($_projectRoot !== false) {
    $absTemporary = $_projectRoot . '/storage/tmp';
    os_ensure_directory($absTemporary, 0700);
    if (is_dir($absTemporary) && is_writable($absTemporary)) {
        ini_set('sys_temp_dir', $absTemporary);
        putenv('TMPDIR=' . $absTemporary);
        os_write_guard_file($absTemporary . '/.htaccess', "Require all denied\n");
    }
    unset($absTemporary);
}
unset($_projectRoot, $environmentTemporaryPath);

define('APP_ENV', get_env('APP_ENV', 'production'));

define('DB_HOST', get_env('DB_HOST', get_env('PGHOST', 'localhost')));

define('DB_PORT', get_env('DB_PORT', get_env('PGPORT', '5432')));

define('DB_CONNECT_TIMEOUT', (int) get_env('DB_CONNECT_TIMEOUT', '5'));

define('DB_NAME', get_env('DB_NAME', get_env('PGDATABASE', '')));

define('DB_USER', get_env('DB_USER', get_env('PGUSER', '')));

define('DB_PASSWORD', get_env('DB_PASSWORD', get_env('PGPASSWORD', '')));

define('DB_SCHEMA', get_env('DB_SCHEMA', get_env('PGSCHEMA', 'app')));

define('APP_TIMEZONE', get_env('APP_TIMEZONE', 'Europe/Warsaw'));

define('SECURE_COOKIES', get_env('SECURE_COOKIES', 'true') === 'true');

define('SESSION_SAMESITE', get_env('SESSION_SAMESITE', 'Lax'));

define('SESSION_MAX_LIFETIME', (int) get_env('SESSION_MAX_LIFETIME', '28800'));

define('SESSION_IDLE_TIMEOUT', max(0, (int) get_env('SESSION_IDLE_TIMEOUT', '0')));

ini_set('session.gc_maxlifetime', (string) SESSION_MAX_LIFETIME);

(static function (): void {
    $environment = get_env('IP_HASH_SALT', '');
    if ($environment !== '') {
        define('IP_HASH_SALT', $environment);
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
    $environment = get_env('APP_ENCRYPTION_KEY', '');
    if ($environment !== '') {
        define('APP_ENCRYPTION_KEY', $environment);
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

define('ARGON2_OPTIONS', [
    'memory_cost' => max(8192, (int) get_env('ARGON2_MEMORY_COST', (string) (1 << 17))),
    'time_cost'   => max(1, (int) get_env('ARGON2_TIME_COST', '4')),
    'threads'     => max(1, (int) get_env('ARGON2_THREADS', '1')),
]);

define('PASSWORD_MIN_LENGTH', max(8, (int) get_env('PASSWORD_MIN_LENGTH', '12')));

define('LOGIN_MAX_ATTEMPTS_PER_IP', max(1, (int) get_env('LOGIN_MAX_ATTEMPTS_PER_IP', '20')));

define('LOGIN_MAX_ATTEMPTS_PER_USERNAME', max(1, (int) get_env('LOGIN_MAX_ATTEMPTS_PER_USERNAME', '5')));

define('LOGIN_LOCKOUT_MINUTES', max(1, (int) get_env('LOGIN_LOCKOUT_MINUTES', '15')));

define('LOGIN_RATE_LIMIT_WINDOW_MINUTES', max(1, (int) get_env('LOGIN_RATE_LIMIT_WINDOW_MINUTES', '15')));

define('ADMIN_PURGE_MAX_DAYS', max(1, (int) get_env('ADMIN_PURGE_MAX_DAYS', '3650')));

define('CSP_REPORT_URI', (static function (): string {
    $reportUri = trim(get_env('CSP_REPORT_URI', ''));
    if ($reportUri === '' || preg_match('/[\s;,]/', $reportUri) === 1) {
        return '';
    }
    return $reportUri;
})());

define('CSP_REPORT_ONLY', CSP_REPORT_URI !== '' && get_env('CSP_REPORT_ONLY', 'false') === 'true');

define('TRUST_PROXY_HEADERS', get_env('TRUST_PROXY_HEADERS', 'false') === 'true');

define('FORWARDED_HEADER_PRIORITY', (static function (): array {
    $supported = ['cf', 'x-real-ip', 'x-forwarded-for'];
    $sources   = [];
    foreach (explode(',', get_env('FORWARDED_HEADER_PRIORITY', 'cf,x-real-ip')) as $entry) {
        $entry = strtolower(trim($entry));
        if (in_array($entry, $supported, true) && !in_array($entry, $sources, true)) {
            $sources[] = $entry;
        }
    }
    return $sources;
})());

define('TRUSTED_PROXY_IPS', (static function (): array {
    $raw = get_env('TRUSTED_PROXY_IPS', '');
    if (trim($raw) === '') {
        return [];
    }
    $ranges = [];
    foreach (explode(',', $raw) as $entry) {
        $entry = trim($entry);
        if ($entry !== '') {
            $ranges[] = $entry;
        }
    }
    return $ranges;
})());

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
    $environment = get_env('AUTOMATION_EMAIL_FROM', '');
    return $environment !== '' ? $environment : (string) settings_value('automation_email_from', '');
})());

define('AUTOMATION_EMAIL_BATCH_LIMIT', (int) get_env('AUTOMATION_EMAIL_BATCH_LIMIT', '50'));

define('AUTOMATION_EMAIL_MAX_ATTEMPTS', (int) get_env('AUTOMATION_EMAIL_MAX_ATTEMPTS', '3'));

define('CONFIG_FILE_MAX_BYTES', (int) get_env('CONFIG_FILE_MAX_BYTES', '524288'));

define('MAX_LIST_ROWS', (int) get_env('MAX_LIST_ROWS', '10000'));

define('HSTS_MAX_AGE', (int) get_env('HSTS_MAX_AGE', '31536000'));

define('HTTP_CLIENT_TIMEOUT', max(1, (int) get_env('HTTP_CLIENT_TIMEOUT', '30')));

define('HTTP_CLIENT_CONNECT_TIMEOUT', max(1, (int) get_env('HTTP_CLIENT_CONNECT_TIMEOUT', '10')));

define('RECORD_SNAPSHOTS_ENABLED', (function (): bool {
    $environment = get_env('RECORD_SNAPSHOTS_ENABLED', '');
    if ($environment !== '') {
        return $environment === 'true';
    }
    return (bool) settings_value('record_snapshots_enabled', false);
})());

define('CHAT_BUBBLE_ENABLED', (function (): bool {
    $environment = get_env('CHAT_BUBBLE_ENABLED', '');
    if ($environment !== '') {
        return $environment === 'true';
    }
    return (bool) settings_value('chat_bubble_enabled', false);
})());

define('OLLAMA_URL', get_env('OLLAMA_URL', 'http://localhost:11434'));

define('OLLAMA_MODEL', get_env('OLLAMA_MODEL', 'llama3'));

define('RAG_RATE_LIMIT_PER_MIN', (int) get_env('RAG_RATE_LIMIT_PER_MIN', '10'));

define('RAG_MAX_CONCURRENT', (int) get_env('RAG_MAX_CONCURRENT', '2'));

define('RAG_PAGE_CONTEXT_MAX_CHARS', (int) get_env('RAG_PAGE_CONTEXT_MAX_CHARS', '12000'));
