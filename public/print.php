<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$page      = os_page_bootstrap();
$cspNonce  = $page['nonce'];
$printName = substr(os_query_string('print'), 0, 64);

if ($printName !== '') {
    os_require_access('prints', $printName);
}

$pageTitle      = 'OpenSparrow — Print';
$extraCss       = '<link href="assets/css/print.css" rel="stylesheet">';
$headerControls = os_header_filters('printFilters', 'print-filters')
    . os_header_clear_filters();

$printLabels = ['loading' => t('common.loading')];

ob_start();
include __DIR__ . '/../templates/print.php';
$pageContent = ob_get_clean();

$extraScripts = os_inline_globals([
    'PRINT_INITIAL' => $printName ?: null,
    'CSRF_TOKEN'    => $_SESSION['csrf_token'],
], $cspNonce)
    . os_module_script('assets/js/print.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
