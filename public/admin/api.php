<?php

declare(strict_types=1);

// admin/api.php — Admin-panel REST API front controller
// Auth gate: session + role === 'admin' (403 otherwise); CSRF on POST; DEMO_MODE disables writes.
// ~65 actions dispatched via $adminModules (action → per-domain module under includes/admin/:
// migrations, users, schema, health, backup, settings, config_files, performance, cron, m2m,
// anonymization, rag, automations, overview). Demo actions + unknown-action fallback: demo/seed.php.
// Error envelope: deliberate messages thrown as AdminApiMessage pass to the client via admin_error_message();
// any other Throwable is logged and genericized (never leaks paths/SQL/credentials);
// admin_db_fail() logs raw pg errors and throws AdminApiMessage with a generic message.

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

start_session();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized access. Log in first.']);
    exit;
}

$action = $_GET['action'] ?? '';
$file = $_GET['file'] ?? '';
$isDemoMode = DEMO_MODE;

// Deliberate, user-facing API errors thrown by this endpoint's own validation.
// Catch blocks pass these messages through to the client via admin_error_message();
// every other Throwable (PDO/pg driver errors, JSON or type failures) is logged
// and replaced with a generic message so file paths, SQL or credentials never
// reach the HTTP response. Note: a plain instanceof-RuntimeException whitelist
// would not work here — PDOException extends RuntimeException.
final class AdminApiMessage extends RuntimeException
{
}

// Map a caught exception to a client-safe message (see AdminApiMessage above).
function admin_error_message(Throwable $e): string
{
    if ($e instanceof AdminApiMessage) {
        return $e->getMessage();
    }
    error_log('[admin_api][unhandled] ' . get_class($e) . ': ' . $e->getMessage());
    return 'Internal error. Check server logs for details.';
}

// Never leak raw Postgres errors (schema names, constraint names, column lists)
// into the HTTP response. Details go to the PHP error log; the client gets a
// stable, generic message so the operator knows to check the server logs.
function admin_db_fail($conn, string $context): void
{
    $raw = $conn !== null ? pg_last_error($conn) : 'no connection';
    error_log('[admin_api][' . $context . '] ' . $raw);
    throw new AdminApiMessage('Database operation failed. Check server logs for details.');
}

// Demo Mode guard for admin write actions. Emits the standard error envelope and
// exits when DEMO_MODE is on; $code 0 leaves the HTTP status untouched.
function require_not_demo(string $message = 'Action disabled in Demo Mode.', int $code = 0): void
{
    if (!DEMO_MODE) {
        return;
    }
    if ($code !== 0) {
        http_response_code($code);
    }
    echo json_encode(['status' => 'error', 'error' => $message]);
    exit;
}

// Read config/settings.json into an array, returning [] when missing or unreadable.
function admin_read_settings(string $path): array
{
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $decoded = @json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
    }
    return [];
}

// MySQL Gateway PDO + identifier quoting live in the shared includes/mysql.php module
require_once __DIR__ . '/../../includes/mysql.php';
// CSRF Protection for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'CSRF token mismatch.']);
        exit;
    }
}

// Ensure state-changing actions use POST method to prevent CSRF via GET
$postActions = ['save', 'import', 'init_db', 'users_add', 'users_toggle', 'users_update_role', 'users_change_password', 'create_table', 'add_column', 'schema_add_table', 'run_cron_notifications', 'backup_tables', 'set_snapshot_setting', 'cron_purge_log', 'create_m2m', 'delete_m2m', 'rag_upload', 'rag_delete', 'rag_rechunk', 'rag_rechunk_all', 'rag_settings_save', 'rag_test_query', 'rag_ollama_check', 'automations_save', 'automations_delete', 'anonymization_save', 'run_anonymization', 'anonymization_purge_log', 'upload_logo', 'remove_logo', 'set_logo_enabled', 'set_app_name', 'set_language_setting', 'set_chat_bubble_setting'];
if (in_array($action, $postActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

// Automations config helpers (config/automations.json) — shared by the
// automations and overview modules, so they live in the front controller.
function auto_cfg_path(): string
{
    return __DIR__ . '/../../config/automations.json';
}

function auto_cfg_read(): array
{
    $path = auto_cfg_path();
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return $data['automations'] ?? [];
}

function auto_cfg_write(array $automations): void
{
    $path    = auto_cfg_path();
    $json    = json_encode(['automations' => array_values($automations)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmpPath, $json, LOCK_EX);
    rename($tmpPath, $path);
}

// ── Action → module dispatch ─────────────────────────────────────────────────
// The action blocks live in per-domain modules under includes/admin/ (outside
// the docroot). Every block is self-contained: it sets its own Content-Type
// and exits. An action absent from this map — or a block whose guard does not
// match (e.g. 'get' with a non-whitelisted file) — falls through to
// demo/seed.php, exactly as before the split.
$adminModules = [
    'run_cron_notifications' => 'cron',
    'cron_log' => 'cron',
    'cron_stats' => 'cron',
    'cron_purge_log' => 'cron',
    'init_db' => 'migrations',
    'migrations_list' => 'migrations',
    'users_list' => 'users',
    'users_add' => 'users',
    'users_toggle' => 'users',
    'users_update_role' => 'users',
    'users_change_password' => 'users',
    'create_table' => 'schema',
    'add_column' => 'schema',
    'schema_add_table' => 'schema',
    'list_system_tables' => 'schema',
    'sync_schema' => 'schema',
    'get_db_columns' => 'schema',
    'health' => 'health',
    'export' => 'backup',
    'import' => 'backup',
    'backup_tables' => 'backup',
    'list_icons' => 'settings',
    'get_snapshot_setting' => 'settings',
    'set_snapshot_setting' => 'settings',
    'get_language_setting' => 'settings',
    'set_language_setting' => 'settings',
    'get_chat_bubble_setting' => 'settings',
    'set_chat_bubble_setting' => 'settings',
    'get_logo_setting' => 'settings',
    'set_logo_enabled' => 'settings',
    'set_app_name' => 'settings',
    'upload_logo' => 'settings',
    'remove_logo' => 'settings',
    'menu_config' => 'config_files',
    'get' => 'config_files',
    'save' => 'config_files',
    'performance_check' => 'performance',
    'performance_slow_queries' => 'performance',
    'performance_table_stats' => 'performance',
    'performance_db_health' => 'performance',
    'performance_unused_indexes' => 'performance',
    'performance_schema_warnings' => 'performance',
    'list_m2m' => 'm2m',
    'create_m2m' => 'm2m',
    'delete_m2m' => 'm2m',
    'anonymization_load' => 'anonymization',
    'anonymization_save' => 'anonymization',
    'run_anonymization' => 'anonymization',
    'preview_anonymization' => 'anonymization',
    'anonymization_log' => 'anonymization',
    'anonymization_purge_log' => 'anonymization',
    'rag_list' => 'rag',
    'rag_upload' => 'rag',
    'rag_delete' => 'rag',
    'rag_rechunk' => 'rag',
    'rag_rechunk_all' => 'rag',
    'rag_settings' => 'rag',
    'rag_settings_save' => 'rag',
    'rag_test_query' => 'rag',
    'rag_ollama_check' => 'rag',
    'rag_stats' => 'rag',
    'automations_runs' => 'automations',
    'automations_list' => 'automations',
    'automations_save' => 'automations',
    'automations_delete' => 'automations',
    'overview' => 'overview',
];

$adminModule = $adminModules[$action] ?? null;
if ($adminModule !== null) {
    require __DIR__ . '/../../includes/admin/' . $adminModule . '.php';
}

require_once __DIR__ . '/demo/seed.php';
