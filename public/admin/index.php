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
    $tUsers = sys_table('users');

    $sqlState = null;
    if (@pg_send_query($_conn, "SELECT 1 FROM $tUsers LIMIT 1")) {
        $chk = @pg_get_result($_conn);
        if ($chk !== false) {
            $sqlState = pg_result_error_field($chk, PGSQL_DIAG_SQLSTATE);
        }
        while (@pg_get_result($_conn)) {
        }
    }
    $firstRun = ($sqlState === '42P01');
}
unset($_conn, $chk, $sqlState, $tUsers);

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
                'icon'   => $navIcon('health_and_safety.png'),
                'active' => true,
            ],
        ],
    ],
    [
        'label' => 'Data Management',
        'icon'  => $navIcon('data_table.png'),
        'items' => [
            ['file' => 'board', 'label' => 'Board', 'icon' => $navIcon('account_tree.png')],
            ['file' => 'calendar', 'label' => 'Calendar', 'icon' => $navIcon('manage_history.png')],
            ['file' => 'csv_import', 'label' => 'CSV Import', 'icon' => $navIcon('upload.png')],
            ['file' => 'dashboard', 'label' => 'Dashboard', 'icon' => $navIcon('ballot.png')],
            ['file' => 'etl', 'label' => 'ETL', 'icon' => $navIcon('database.png')],
            ['file' => 'files', 'label' => 'Files', 'icon' => $navIcon('upload.png')],
            ['file' => 'print', 'label' => 'Printouts', 'icon' => $navIcon('picture_as_pdf.png')],
            ['file' => 'schema', 'label' => 'Schema', 'icon' => $navIcon('data_table.png')],
            ['file' => 'user_records', 'label' => 'User Records', 'icon' => $navIcon('id_card.png')],
            ['file' => 'views', 'label' => 'Views', 'icon' => $navIcon('table_chart_view.png')],
        ],
    ],
    [
        'label' => 'Workflows',
        'icon'  => $navIcon('build.png'),
        'items' => [
            ['file' => 'automations', 'label' => 'Automations', 'icon' => $navIcon('automation.png')],
            ['file' => 'workflows', 'label' => 'Workflow Manager', 'icon' => $navIcon('build.png')],
        ],
    ],
    [
        'label' => 'Knowledge Base',
        'icon'  => $navIcon('menu_book.png'),
        'items' => [
            ['file' => 'rag', 'label' => 'RAG Documents', 'icon' => $navIcon('docs.png')],
        ],
    ],
    [
        'label' => 'System',
        'icon'  => $navIcon('database.png'),
        'items' => [
            ['file' => 'anonymization', 'label' => 'Anonymization', 'icon' => $navIcon('fact_check.png')],
            ['file' => 'backup', 'label' => 'Backup Tables', 'icon' => $navIcon('inventory.png')],
            ['file' => 'clickstats', 'label' => 'Click Statistics', 'icon' => $navIcon('bar_chart.png')],
            ['file' => 'cron', 'label' => 'Cron Notifications', 'icon' => $navIcon('manage_history.png')],
            ['file' => 'demo', 'label' => 'Demo Systems', 'icon' => $navIcon('playground.png')],
            ['file' => 'health', 'label' => 'Health Check', 'icon' => $navIcon('health_and_safety.png')],
            ['file' => 'migrations', 'label' => 'Migrations', 'icon' => $navIcon('database.png')],
            ['file' => 'performance', 'label' => 'Performance', 'icon' => $navIcon('health_and_safety.png')],
            ['file' => 'settings', 'label' => 'Settings', 'icon' => $navIcon('manage_history.png')],
            ['file' => 'users', 'label' => 'Users', 'icon' => $navIcon('user_attributes.png')],
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
