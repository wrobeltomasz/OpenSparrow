<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/bootstrap.php';

use App\Exception\ForbiddenException;
use App\Exception\HttpException;
use App\Exception\UnauthorizedException;

ini_set('display_errors', '0');
os_register_exception_handler('json');
start_session();
send_security_headers();

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    throw new UnauthorizedException(
        'Unauthorized access. Log in first.',
        ['status' => 'error', 'error' => 'Unauthorized access. Log in first.']
    );
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    throw new ForbiddenException(
        'Forbidden: admin role required.',
        ['status' => 'error', 'error' => 'Forbidden: admin role required.']
    );
}

$action = $_GET['action'] ?? '';
$file = $_GET['file'] ?? '';
$isDemoMode = DEMO_MODE;

require_once __DIR__ . '/../../includes/admin_api_errors.php';

require_once __DIR__ . '/../../includes/admin/helpers.php';

if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'DELETE'], true)) {
    $csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? os_request()->post('csrf_token'));
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        throw new ForbiddenException(
            'CSRF token mismatch.',
            ['status' => 'error', 'error' => 'CSRF token mismatch.']
        );
    }
}

$postActions = [
    'save', 'init_db',
    'users_add', 'users_toggle', 'users_update_role', 'users_update_contact',
    'users_change_password', 'user_policy_save',
    'user_tables_save',
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
    'clickstats_save', 'clickstats_purge_log',
    'demo_install', 'demo_uninstall',
];
if (in_array($action, $postActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw HttpException::fromStatus(
        405,
        'Method Not Allowed. Use POST.',
        ['status' => 'error', 'error' => 'Method Not Allowed. Use POST.']
    );
}

require_once __DIR__ . '/../../includes/config_store.php';

function auto_cfg_read(): array
{
    $data = config_get('automations');
    return is_array($data) ? ($data['automations'] ?? []) : [];
}

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

$adminModules = [
    'run_cron_notifications' => 'cron',
    'cron_log' => 'cron',
    'cron_stats' => 'cron',
    'cron_purge_log' => 'cron',
    'clickstats_load' => 'clickstats',
    'clickstats_save' => 'clickstats',
    'clickstats_log' => 'clickstats',
    'clickstats_purge_log' => 'clickstats',
    'init_db' => 'migrations',
    'migrations_list' => 'migrations',
    'users_list' => 'users',
    'users_add' => 'users',
    'users_toggle' => 'users',
    'users_update_role' => 'users',
    'users_update_contact' => 'users',
    'users_change_password' => 'users',
    'users_stats' => 'users',
    'user_policy_get' => 'users',
    'user_policy_save' => 'users',
    'user_tables_get' => 'users',
    'user_tables_save' => 'users',
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
