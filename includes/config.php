<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// config.php — Central configuration loader (environment variables + JSON files)
// Defines constants for DB, session, security, rate limiting, file uploads, comments, notifications, snapshots, chat bubble, RAG, MySQL gateway
// Sets session.save_path to absolute path under project root; configures session.gc_maxlifetime; detects HTTPS behind reverse proxies
// Generates IP_HASH_SALT for login throttling if not set via env; resolves TRUST_PROXY_HEADERS for client_ip()
// Includes version.php and defines OPENSPARROW_CONFIG_LOADED guard to prevent double inclusion

declare(strict_types=1);

// Functions are declared OUTSIDE the OPENSPARROW_CONFIG_LOADED guard and
// wrapped in function_exists(). If something (e.g. auto_prepend_file, an
// opcache-cached older copy of this file, or an unrelated define()) sets
// OPENSPARROW_CONFIG_LOADED before this file runs, the guard short-circuits
// the constants block — but get_env() / client_ip() must still be defined or
// login.php fatals with "Call to undefined function client_ip()".
if (!function_exists('get_env')) {
    function get_env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }

}

// Resolve the real client IP. Behind a reverse proxy (CloudFlare, Nginx), $_SERVER['REMOTE_ADDR']
// points to the proxy, not the user — breaking rate limiting (all users appear to share one IP).
// CloudFlare adds HTTP_CF_CONNECTING_IP + HTTP_CF_RAY signature; we only trust them together.
// Generic proxies set HTTP_X_REAL_IP. Falls back to REMOTE_ADDR for direct connections (localhost).
if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        // When the app is directly reachable (not strictly behind the trusted
        // proxy), operators can set TRUST_PROXY_HEADERS=false so spoofed
        // forwarding headers cannot be used to evade per-IP login rate limiting.
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

if (defined('OPENSPARROW_CONFIG_LOADED')) {
    return;
}
define('OPENSPARROW_CONFIG_LOADED', true);
require_once __DIR__ . '/version.php';
// Detect HTTPS through reverse proxy (CloudFlare, Nginx, load balancer).
// Required: when behind proxy, $_SERVER['HTTPS'] is empty even though client uses HTTPS.
// PHP's session secure cookie flag depends on this — without it, sessions break.
if (
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
    (!empty($_SERVER['HTTP_CF_VISITOR']) && stripos($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false) ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}

// Force absolute path for session storage.
// PHP-FPM may chdir to the script's directory, so a relative save_path like "tmp"
// resolves to "./tmp" — different folder per script location, breaking session
// continuity between /login.php and /admin/index.php.
// Resolves relative paths against project root (parent of includes/).
// Force an absolute session save path so that PHP-FPM pools that inherit
// a system-level session.save_path (e.g. /tmp, blocked by open_basedir on
// shared hosts) do not break session continuity between subdirectories.
// Priority: SESSION_SAVE_PATH env var > project storage/sessions fallback.
$_projectRoot = realpath(__DIR__ . '/..');
$_envSessPath = get_env('SESSION_SAVE_PATH', '');
if ($_envSessPath !== '') {
    ini_set('session.save_path', $_envSessPath);
} elseif ($_projectRoot !== false) {
    $_absPath = $_projectRoot . '/storage/sessions';
    if (!is_dir($_absPath)) {
        @mkdir($_absPath, 0700, true);
    }
    if (is_dir($_absPath) && is_writable($_absPath)) {
        ini_set('session.save_path', $_absPath);
        $_htaccess = $_absPath . '/.htaccess';
        if (!is_file($_htaccess)) {
            @file_put_contents($_htaccess, "Require all denied\n");
        }
        unset($_htaccess);
    }
    unset($_absPath);
}
unset($_projectRoot, $_envSessPath);
// -------------------------------------------------------------------------
// Runtime environment
// -------------------------------------------------------------------------

// Application environment. Controls environment-specific behaviour such as
// error reporting verbosity. Set to "development" during local development.
define('APP_ENV', get_env('APP_ENV', 'production'));
// -------------------------------------------------------------------------
// Database connection
// -------------------------------------------------------------------------

// Hostname of the PostgreSQL server. Falls back to the standard PGHOST env var.
define('DB_HOST', get_env('DB_HOST', get_env('PGHOST', 'localhost')));
// Port the PostgreSQL server listens on. Falls back to the standard PGPORT env var.
define('DB_PORT', get_env('DB_PORT', get_env('PGPORT', '5432')));
// Maximum number of seconds to wait when establishing a database connection
// before giving up and throwing a RuntimeException.
define('DB_CONNECT_TIMEOUT', (int) get_env('DB_CONNECT_TIMEOUT', '5'));
// PostgreSQL time zone applied at the session level immediately after connecting.
// Must be a valid IANA tz database identifier (e.g. "UTC", "Europe/Warsaw").
define('APP_TIMEZONE', get_env('APP_TIMEZONE', 'Europe/Warsaw'));
// -------------------------------------------------------------------------
// Session & cookies
// -------------------------------------------------------------------------

// Controls the Secure flag on session cookies. Set to "false" on plain HTTP
// (local development without TLS); always "true" in production.
define('SECURE_COOKIES', get_env('SECURE_COOKIES', 'true') === 'true');
// SameSite policy for session cookies. "Lax" is the recommended value — it
// blocks cross-site POST requests while allowing same-site redirects.
// "Strict" breaks logins that redirect across paths (e.g. login.php → admin/).
define('SESSION_SAMESITE', get_env('SESSION_SAMESITE', 'Lax'));
// Maximum session lifetime in seconds. After this period the user is logged out
// regardless of browser cookie state. Default is 8 hours (28 800 s).
define('SESSION_MAX_LIFETIME', (int) get_env('SESSION_MAX_LIFETIME', '28800'));
// Sync PHP's garbage collector with our app-level session lifetime.
// Default php.ini session.gc_maxlifetime is 1440 s (24 min) — PHP could delete
// session files long before SESSION_MAX_LIFETIME, silently logging users out.
ini_set('session.gc_maxlifetime', (string) SESSION_MAX_LIFETIME);
// -------------------------------------------------------------------------
// Authentication & rate limiting
// -------------------------------------------------------------------------

// HMAC-SHA256 secret used to pseudonymise client IP addresses before storing
// them in the login_attempts table. Prefer the IP_HASH_SALT env var in production.
// When neither env nor stored file is present, generate a 64-char random salt
// and persist to includes/.secret_salt (web-denied by includes/.htaccess + gitignored).
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
        @file_put_contents($file, $stored, LOCK_EX);
        @chmod($file, 0600);
    }
    define('IP_HASH_SALT', $stored);
})();
// Maximum number of failed login attempts allowed from a single IP address
// within the lockout window before that IP is temporarily blocked.
define('LOGIN_MAX_ATTEMPTS_PER_IP', (int) get_env('LOGIN_MAX_ATTEMPTS_PER_IP', '20'));
// Maximum number of failed login attempts allowed for a single username
// within the lockout window before that account is temporarily blocked.
define('LOGIN_MAX_ATTEMPTS_PER_USERNAME', (int) get_env('LOGIN_MAX_ATTEMPTS_PER_USERNAME', '5'));
// Duration in minutes for which a locked-out IP or username is blocked
// after exceeding the respective attempt threshold.
define('LOGIN_LOCKOUT_MINUTES', (int) get_env('LOGIN_LOCKOUT_MINUTES', '15'));
// Whether to trust reverse-proxy client-IP headers (CF-Connecting-IP, X-Real-IP)
// when resolving the client address for login rate limiting. Keep "true" behind
// the bundled nginx/Cloudflare setup; set "false" when the app is directly
// reachable so a client cannot spoof these headers to bypass per-IP throttling.
define('TRUST_PROXY_HEADERS', get_env('TRUST_PROXY_HEADERS', 'true') === 'true');
// -------------------------------------------------------------------------
// Demo mode
// -------------------------------------------------------------------------

// When true, write operations (save, delete, import) are blocked in the admin
// panel API, allowing safe public demonstrations without risking data changes.
define('DEMO_MODE', get_env('DEMO_MODE', 'false') === 'true');
// -------------------------------------------------------------------------
// File storage & uploads
// -------------------------------------------------------------------------

// Default maximum upload size in megabytes, used when the value is not
// specified in the files module configuration (config/files.json).
define('FILES_MAX_SIZE_MB', (int) get_env('FILES_MAX_SIZE_MB', '20'));
// Default number of file records returned per page in the file listing API.
define('FILES_PAGE_LIMIT', (int) get_env('FILES_PAGE_LIMIT', '25'));
// Hard ceiling on the number of file records a single API request may return,
// regardless of the "limit" query parameter supplied by the client.
define('FILES_PAGE_LIMIT_MAX', (int) get_env('FILES_PAGE_LIMIT_MAX', '100'));
// Maximum pixel width for generated image thumbnails. The height is scaled
// proportionally. Images narrower than this value are served as-is.
define('THUMBNAIL_MAX_WIDTH', (int) get_env('THUMBNAIL_MAX_WIDTH', '300'));
// Cache-Control max-age in seconds for standard file downloads (private cache).
define('FILE_CACHE_MAX_AGE', (int) get_env('FILE_CACHE_MAX_AGE', '3600'));
// Cache-Control max-age in seconds for generated thumbnails (public cache).
// Longer than FILE_CACHE_MAX_AGE because thumbnails are deterministic and safe
// to store in shared caches.
define('THUMBNAIL_CACHE_MAX_AGE', (int) get_env('THUMBNAIL_CACHE_MAX_AGE', '86400'));
// -------------------------------------------------------------------------
// Comments
// -------------------------------------------------------------------------

// Hard ceiling on the number of comments a single API request may return when
// a "limit" query parameter is provided (used for preview/inline widgets).
define('COMMENTS_PAGE_LIMIT_MAX', (int) get_env('COMMENTS_PAGE_LIMIT_MAX', '50'));
// -------------------------------------------------------------------------
// Notifications
// -------------------------------------------------------------------------

// Maximum number of notifications fetched for the dropdown bell menu.
// Increasing this value affects header rendering time for every page load.
define('NOTIFICATIONS_DROPDOWN_LIMIT', (int) get_env('NOTIFICATIONS_DROPDOWN_LIMIT', '10'));
// -------------------------------------------------------------------------
// Admin panel
// -------------------------------------------------------------------------

// Maximum allowed size in bytes for JSON configuration files read by the admin
// panel (schema.json, menu.json, etc.). Prevents memory exhaustion from
// unexpectedly large or corrupt files.
define('CONFIG_FILE_MAX_BYTES', (int) get_env('CONFIG_FILE_MAX_BYTES', '524288'));
// Hard ceiling on the number of rows returned by the grid list API (api=list).
// Prevents PHP memory exhaustion on very large tables. Per-table initial_limit
// in schema.json takes precedence when set; this is the global fallback.
// Override via env var for installations with high-memory PHP pools.
define('MAX_LIST_ROWS', (int) get_env('MAX_LIST_ROWS', '10000'));
// -------------------------------------------------------------------------
// HTTP security headers
// -------------------------------------------------------------------------

// max-age value (in seconds) for the Strict-Transport-Security header.
// Default is 1 year (31 536 000 s). Set to 0 to disable HSTS on plain HTTP.
define('HSTS_MAX_AGE', (int) get_env('HSTS_MAX_AGE', '31536000'));
// -------------------------------------------------------------------------
// Audit & record snapshots
// -------------------------------------------------------------------------

// When true, a JSONB snapshot of every inserted/updated/deleted record is
// saved to spw_record_snapshots and linked to the corresponding audit log entry.
// Can be toggled at runtime via Admin → System → Audit & Snapshots.
// The RECORD_SNAPSHOTS_ENABLED env var takes precedence over the settings file.
define('RECORD_SNAPSHOTS_ENABLED', (function (): bool {

    $env = get_env('RECORD_SNAPSHOTS_ENABLED', '');
    if ($env !== '') {
        return $env === 'true';
    }
    $f = __DIR__ . '/../config/settings.json';
    if (is_file($f)) {
        $s = @json_decode((string) file_get_contents($f), true);
        if (is_array($s) && array_key_exists('record_snapshots_enabled', $s)) {
            return (bool) $s['record_snapshots_enabled'];
        }
    }
    return false;
})());
// -------------------------------------------------------------------------
// AI Chat Bubble
// -------------------------------------------------------------------------

// When true, a floating chat button appears in the bottom-right corner of
// every app page. Can be toggled via Admin → Settings.
// The CHAT_BUBBLE_ENABLED env var takes precedence over the settings file.
define('CHAT_BUBBLE_ENABLED', (function (): bool {
    $env = get_env('CHAT_BUBBLE_ENABLED', '');
    if ($env !== '') {
        return $env === 'true';
    }
    $f = __DIR__ . '/../config/settings.json';
    if (is_file($f)) {
        $s = @json_decode((string) file_get_contents($f), true);
        if (is_array($s) && array_key_exists('chat_bubble_enabled', $s)) {
            return (bool) $s['chat_bubble_enabled'];
        }
    }
    return false;
})());
// -------------------------------------------------------------------------
// RAG (Retrieval-Augmented Generation)
// -------------------------------------------------------------------------

// Base URL of the local Ollama instance. Override via env or config/rag.json.
define('OLLAMA_URL', get_env('OLLAMA_URL', 'http://localhost:11434'));
// Default Ollama model name. Override via env or config/rag.json.
define('OLLAMA_MODEL', get_env('OLLAMA_MODEL', 'llama3'));
// Maximum number of assistant queries a single user may submit per minute.
// Set to 0 to disable per-user rate limiting.
define('RAG_RATE_LIMIT_PER_MIN', (int) get_env('RAG_RATE_LIMIT_PER_MIN', '10'));
// Maximum number of assistant queries processed concurrently across the server.
// Protects the PHP-FPM pool from being exhausted by slow Ollama calls. Set to 0 to disable.
define('RAG_MAX_CONCURRENT', (int) get_env('RAG_MAX_CONCURRENT', '2'));
// Hard backstop on the character length of the grid page context accepted from the
// client. The client already limits rows and columns; this guards against a tampered
// client sending an oversized payload.
define('RAG_PAGE_CONTEXT_MAX_CHARS', (int) get_env('RAG_PAGE_CONTEXT_MAX_CHARS', '12000'));
// -------------------------------------------------------------------------
// MySQL Gateway (External Databases)
// -------------------------------------------------------------------------

// Credentials resolved from env vars first, then config/mysql_connection.json
// (gitignored, web-denied by config/.htaccess). Env vars always take precedence.
(static function (): void {
    $envHost = get_env('MYSQL_HOST', '');
    $envPort = get_env('MYSQL_PORT', '');
    $envDb   = get_env('MYSQL_DB', '');
    $envUser = get_env('MYSQL_USER', '');
    $envPass = get_env('MYSQL_PASSWORD', '');

    $file = [];
    if ($envHost === '' || $envDb === '' || $envUser === '') {
        $path    = __DIR__ . '/../config/mysql_connection.json';
        $raw     = is_file($path) ? @file_get_contents($path) : false;
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $file = $decoded;
        }
    }

    define('MYSQL_HOST', $envHost !== '' ? $envHost : (string) ($file['host']     ?? ''));
    define('MYSQL_PORT', $envPort !== '' ? (int) $envPort : (int) ($file['port'] ?? 3306));
    define('MYSQL_DB', $envDb   !== '' ? $envDb   : (string) ($file['database'] ?? ''));
    define('MYSQL_USER', $envUser !== '' ? $envUser : (string) ($file['user']     ?? ''));
    define('MYSQL_PASSWORD', $envPass !== '' ? $envPass : (string) ($file['password'] ?? ''));
})();

// Strict connect timeout (seconds) applied to every MySQL gateway PDO connection
// (both the DSN connect_timeout and PDO::ATTR_TIMEOUT). Kept deliberately low so a
// firewalled, hung, or dead MySQL host fails fast instead of tying up a PHP worker
// long enough to starve the core PostgreSQL request path. Floor of 1s — never 0,
// which several drivers treat as "no timeout". Env-overridable.
define('MYSQL_CONNECT_TIMEOUT', max(1, (int) get_env('MYSQL_CONNECT_TIMEOUT', '2')));
