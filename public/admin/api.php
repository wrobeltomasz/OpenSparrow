<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// admin/api.php — Admin-panel REST API front controller
// Auth gate: session + role === 'admin' (403 otherwise); CSRF on POST/PATCH/DELETE;
// DEMO_MODE disables writes (per-action require_not_demo(), no central gate).
// ~85 actions dispatched via $adminModules (action → per-domain module under includes/admin/:
// migrations, users, schema, health, backup, settings, config_files, performance, cron, m2m,
// anonymization, rag, automations, dashboard, overview). Demo actions + unknown-action fallback: demo/seed.php.
// Error envelope: deliberate messages thrown as AdminApiMessage pass to the client via admin_error_message();
// any other Throwable is logged and genericized (never leaks paths/SQL/credentials);
// admin_db_fail() logs raw pg errors and throws AdminApiMessage with a generic message.

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

// This endpoint keeps its own gate order (it needs $isDemoMode and a per-action
// dispatch) instead of os_api_bootstrap(), so it has to send the same hardening
// that os_api_bootstrap() does: errors off + security headers.
ini_set('display_errors', '0');
start_session();
send_security_headers();

// Every action of this endpoint answers with JSON, so the type is set once here
// instead of being repeated in each of the ~90 action blocks.
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized access. Log in first.']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Forbidden: admin role required.']);
    exit;
}

$action = $_GET['action'] ?? '';
$file = $_GET['file'] ?? '';
$isDemoMode = DEMO_MODE;

// Deliberate, user-facing API errors (AdminApiMessage) and the generic error-envelope
// helpers (admin_error_message, admin_db_fail) live in includes/admin_api_errors.php
// so public/setup_api.php can reuse demo_install_run() without duplicating them.
// Note: a plain instanceof-RuntimeException whitelist would not work for
// admin_error_message() — PDOException extends RuntimeException too.
require_once __DIR__ . '/../../includes/admin_api_errors.php';

// Shared response/connection/config helpers for the includes/admin/ modules
// (admin_try, admin_ok, admin_err, admin_conn, admin_purge_log, …).
require_once __DIR__ . '/../../includes/admin/helpers.php';

// CSRF Protection for state-changing requests. Mirrors os_api_bootstrap()
// (includes/bootstrap.php) — POST/PATCH/DELETE — so a mutating action that
// slips off the $postActions whitelist below is still covered on those verbs.
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'DELETE'], true)) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'CSRF token mismatch.']);
        exit;
    }
}

// Ensure state-changing actions use POST method to prevent CSRF via GET
// Every action with a side effect must be listed here: CSRF is only validated on
// POST, so a mutating action reachable via GET bypasses the token check entirely.
$postActions = [
    'save', 'init_db',
    'users_add', 'users_toggle', 'users_update_role', 'users_change_password', 'user_policy_save',
    'create_table', 'add_column', 'schema_add_table',
    'run_cron_notifications', 'cron_purge_log',
    'backup_tables',
    'set_snapshot_setting', 'set_language_setting', 'set_chat_bubble_setting',
    'set_logo_enabled', 'set_app_name', 'upload_logo', 'remove_logo',
    'set_automation_email_setting', 'test_smtp_connection',
    'create_m2m', 'delete_m2m',
    'rag_upload', 'rag_delete', 'rag_rechunk', 'rag_rechunk_all',
    'rag_settings_save', 'rag_test_query', 'rag_ollama_check', 'rag_aggregate_view_save',
    'automations_save', 'automations_delete',
    'anonymization_save', 'run_anonymization', 'preview_anonymization', 'anonymization_purge_log',
    'etl_save', 'run_etl', 'etl_purge_log', 'etl_test_connection', 'etl_preview',
    'etl_flow_save', 'run_etl_flow', 'etl_flow_purge_log',
    'demo_install', 'demo_uninstall',
];
if (in_array($action, $postActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

// Automations config helpers (spw_config key "automations", via the config
// store) — shared by the automations and overview modules, so they live in
// the front controller.
require_once __DIR__ . '/../../includes/config_store.php';

function auto_cfg_read(): array
{
    $data = config_get('automations');
    return is_array($data) ? ($data['automations'] ?? []) : [];
}

// Throws AdminApiMessage on failure so the callers' try/catch reports it — reporting
// "saved" on a rejected write leaves the admin believing an edited rule is live when
// the stored rule is unchanged. Mirrors admin_config_save_versioned() in
// includes/admin/helpers.php, minus the optimistic-lock version (not echoed here).
function auto_cfg_write(array $automations): void
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $result = config_save('automations', ['automations' => array_values($automations)], null, $userId);
    if (($result['status'] ?? '') === 'conflict') {
        throw new AdminApiMessage('Config was modified by someone else — reload and retry.');
    }
    if (($result['status'] ?? '') !== 'ok') {
        throw new AdminApiMessage($result['error'] ?? 'Failed to save automations config.');
    }
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
    'users_stats' => 'users',
    'user_policy_get' => 'users',
    'user_policy_save' => 'users',
    'create_table' => 'schema',
    'add_column' => 'schema',
    'schema_add_table' => 'schema',
    'list_system_tables' => 'schema',
    'sync_schema' => 'schema',
    'get_db_columns' => 'schema',
    'health' => 'health',
    'backup_tables' => 'backup',
    'list_icons' => 'settings',
    'get_snapshot_setting' => 'settings',
    'set_snapshot_setting' => 'settings',
    'get_automation_email_setting' => 'settings',
    'set_automation_email_setting' => 'settings',
    'test_smtp_connection' => 'settings',
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
    'list_procedures' => 'procedures',
    'anonymization_load' => 'anonymization',
    'anonymization_save' => 'anonymization',
    'run_anonymization' => 'anonymization',
    'preview_anonymization' => 'anonymization',
    'anonymization_log' => 'anonymization',
    'anonymization_purge_log' => 'anonymization',
    'etl_load' => 'etl',
    'etl_save' => 'etl',
    'etl_test_connection' => 'etl',
    'etl_preview' => 'etl',
    'etl_target_schemas' => 'etl',
    'etl_target_tables' => 'etl',
    'run_etl' => 'etl',
    'etl_log' => 'etl',
    'etl_purge_log' => 'etl',
    'etl_flow_load' => 'etl_flow',
    'etl_flow_save' => 'etl_flow',
    'run_etl_flow' => 'etl_flow',
    'etl_flow_log' => 'etl_flow',
    'etl_flow_purge_log' => 'etl_flow',
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
    'rag_aggregate_view_list' => 'rag',
    'rag_aggregate_view_save' => 'rag',
    'automations_runs' => 'automations',
    'automations_list' => 'automations',
    'automations_save' => 'automations',
    'automations_delete' => 'automations',
    'dashboard_calculate' => 'dashboard',
    'overview' => 'overview',
];

$adminModule = $adminModules[$action] ?? null;
if ($adminModule !== null) {
    require __DIR__ . '/../../includes/admin/' . $adminModule . '.php';
}

require_once __DIR__ . '/demo/seed.php';
