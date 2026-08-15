<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap(['csp' => 'no-connect']);
$cspNonce = $page['nonce'];
$userRole = $page['role'];
$userCaps = $page['caps'];

$pageTitle = 'OpenSparrow | Dashboard';
$dashPeriods = [
    'all'        => 'dashboard.filter_all',
    'today'      => 'dashboard.filter_today',
    '7d'         => 'dashboard.filter_7d',
    '30d'        => 'dashboard.filter_30d',
    'this_month' => 'dashboard.filter_month',
];
$headerControls = '<label for="dashDateFilter" class="dash-filter-label">'
    . htmlspecialchars(t('dashboard.filter_label'), ENT_QUOTES, 'UTF-8') . '</label>'
    . '<select id="dashDateFilter">';
foreach ($dashPeriods as $dashVal => $dashKey) {
    $headerControls .= '<option value="' . $dashVal . '">'
        . htmlspecialchars(t($dashKey), ENT_QUOTES, 'UTF-8') . '</option>';
}
$headerControls .= '</select>'
    . os_header_filters('dashboardFilters', 'dashboard-filters')
    . os_header_clear_filters();
$dashboardLabels = ['title' => t('dashboard.title')];

ob_start();
include __DIR__ . '/../templates/dashboard.php';
$pageContent = ob_get_clean();

$extraScripts = os_inline_globals(['USER_CAPS' => $userCaps], $cspNonce)
    . os_module_script('assets/js/dashboard.js', $cspNonce, 'assets/js/dashboard/drill-down.js');
include __DIR__ . '/../templates/layout.php';
