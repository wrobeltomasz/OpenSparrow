<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/bootstrap.php';

use App\Exception\RedirectException;
use App\Exception\ResponseException;

os_register_exception_handler('html');

if (!file_exists(__DIR__ . '/../../config/database.json')) {
    throw new RedirectException('../setup.php');
}

start_session();

$firstRun = false;
require_once __DIR__ . '/../../includes/db.php';
$_conn = @db_connect();
if ($_conn) {
    $usersTable = sys_table('users');

    $sqlState = null;
    if (@pg_send_query($_conn, "SELECT 1 FROM $usersTable LIMIT 1")) {
        $checkResult = @pg_get_result($_conn);
        if ($checkResult !== false) {
            $sqlState = pg_result_error_field($checkResult, PGSQL_DIAG_SQLSTATE);
        }
        while (@pg_get_result($_conn)) {
        }
    }
    $firstRun = ($sqlState === '42P01');
}
unset($_conn, $checkResult, $sqlState, $usersTable);

if (!$firstRun && !isset($_SESSION['user_id'])) {
    throw new RedirectException('../login.php');
}

if (!$firstRun && ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    $forbiddenUser = $_SESSION['username'] ?? 'unknown';
    $forbiddenRole = $_SESSION['role'] ?? 'none';
    require __DIR__ . '/templates/forbidden.php';
    throw ResponseException::sent(403);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../../includes/page_helpers.php';
$adminGraph = os_module_graph([
    './js/'                   => __DIR__ . '/js',
    '../assets/js/util/'      => __DIR__ . '/../assets/js/util',
    '../assets/js/dashboard/' => __DIR__ . '/../assets/js/dashboard',
]);

$adminTitle        = 'Sparrow Admin';
$adminUserId       = (int)($_SESSION['user_id'] ?? 0);
$adminCsrfToken    = $_SESSION['csrf_token'];
$adminStyleVersion = asset_version(__DIR__ . '/style.css');
$adminImportMap    = os_import_map($adminGraph['imports']);
$adminAppVersion   = (string) $adminGraph['version'];
$adminLogoutUrl    = '../logout.php';

$navIcon = static fn(string $file): string => '../assets/icons/' . $file;

$navSections = [
    [
        'label' => null,
        'icon'  => null,
        'open'  => true,
        'items' => [
            [
                'file'   => 'overview',
                'label'  => 'Overview',
                'icon'   => $navIcon('material/health_and_safety.svg'),
                'active' => true,
            ],
        ],
    ],
    [
        'label' => 'Data Management',
        'icon'  => $navIcon('material/data_table.svg'),
        'items' => [
            ['file' => 'board', 'label' => 'Board', 'icon' => $navIcon('material/account_tree.svg')],
            ['file' => 'calendar', 'label' => 'Calendar', 'icon' => $navIcon('material/calendar_month.svg')],
            ['file' => 'csv_import', 'label' => 'CSV Import', 'icon' => $navIcon('material/upload.svg')],
            ['file' => 'dashboard', 'label' => 'Dashboard', 'icon' => $navIcon('material/ballot.svg')],
            ['file' => 'etl', 'label' => 'ETL', 'icon' => $navIcon('material/database.svg')],
            ['file' => 'files', 'label' => 'Files', 'icon' => $navIcon('material/folder_open.svg')],
            ['file' => 'print', 'label' => 'Printouts', 'icon' => $navIcon('picture_as_pdf.png')],
            ['file' => 'schema', 'label' => 'Schema', 'icon' => $navIcon('material/data_table.svg')],
            ['file' => 'user_records', 'label' => 'User Records', 'icon' => $navIcon('material/id_card.svg')],
            ['file' => 'views', 'label' => 'Views', 'icon' => $navIcon('material/table_chart_view.svg')],
        ],
    ],
    [
        'label' => 'Workflows',
        'icon'  => $navIcon('material/build.svg'),
        'items' => [
            ['file' => 'automations', 'label' => 'Automations', 'icon' => $navIcon('material/automation.svg')],
            ['file' => 'workflows', 'label' => 'Workflow Manager', 'icon' => $navIcon('material/build.svg')],
        ],
    ],
    [
        'label' => 'Knowledge Base',
        'icon'  => $navIcon('material/menu_book.svg'),
        'items' => [
            ['file' => 'rag', 'label' => 'RAG Documents', 'icon' => $navIcon('material/docs.svg')],
        ],
    ],
    [
        'label' => 'System',
        'icon'  => $navIcon('material/database.svg'),
        'items' => [
            ['file' => 'anonymization', 'label' => 'Anonymization', 'icon' => $navIcon('material/fact_check.svg')],
            ['file' => 'backup', 'label' => 'Backup Tables', 'icon' => $navIcon('material/inventory.svg')],
            ['file' => 'clickstats', 'label' => 'Click Statistics', 'icon' => $navIcon('material/bar_chart.svg')],
            ['file' => 'cron', 'label' => 'Cron Notifications', 'icon' => $navIcon('material/notifications_active.svg')],
            ['file' => 'demo', 'label' => 'Demo Systems', 'icon' => $navIcon('material/playground.svg')],
            ['file' => 'health', 'label' => 'Health Check', 'icon' => $navIcon('material/health_and_safety.svg')],
            ['file' => 'migrations', 'label' => 'Migrations', 'icon' => $navIcon('material/upgrade.svg')],
            ['file' => 'performance', 'label' => 'Performance', 'icon' => $navIcon('material/speed.svg')],
            ['file' => 'settings', 'label' => 'Settings', 'icon' => $navIcon('material/settings.svg')],
            ['file' => 'users', 'label' => 'Users', 'icon' => $navIcon('material/user_attributes.svg')],
        ],
    ],
];

$breadcrumbRoot    = 'Admin';
$breadcrumbCurrent = 'Schema';
$breadcrumbLabels  = [
    'schema'        => 'Schema',
    'dashboard'     => 'Dashboard',
    'calendar'      => 'Calendar',
    'files'         => 'Files',
    'workflows'     => 'Workflows',
    'users'         => 'Users',
    'health'        => 'Health Check',
    'backup'        => 'Backup Tables',
    'docs'          => 'Documentation',
    'performance'   => 'Performance',
    'cron'          => 'Cron Notifications',
    'views'         => 'Views',
    'csv_import'    => 'CSV Import',
    'rag'           => 'RAG Documents',
    'automations'   => 'Automations',
    'etl'           => 'ETL',
    'anonymization' => 'Data Anonymization',
    'print'         => 'Printouts',
];

require __DIR__ . '/templates/header.php';
require __DIR__ . '/templates/nav.php';
require __DIR__ . '/templates/footer.php';
