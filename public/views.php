<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$page      = os_page_bootstrap(['csp' => 'unsafe-style']);
$cspNonce  = $page['nonce'];
$userRole  = $page['role'];
$viewName  = substr(os_query_string('view'), 0, 64);

if ($viewName !== '') {
    os_require_access('views', $viewName);
}

$pageTitle      = 'OpenSparrow — Views';
$extraCss       = '<link href="assets/css/views.css" rel="stylesheet">';

$headerControls = os_header_input('globalSearch')
    . os_header_select('columnFilter', ['' => ''], true)
    . os_header_filters('filterBar', '')
    . os_header_select('groupBy', ['' => ''], true)
    . os_header_clear_filters();

$viewsLabels = ['loading' => t('common.loading')];

ob_start();
include __DIR__ . '/../templates/views.php';
$pageContent = ob_get_clean();

$extraScripts = os_inline_globals([
    'VIEWS_INITIAL' => $viewName ?: null,
    'CSRF_TOKEN'    => $_SESSION['csrf_token'],
], $cspNonce)
    . os_module_script('assets/js/views.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
