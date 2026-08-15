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
$viewName  = substr($_GET['view'] ?? '', 0, 64);

if ($viewName !== '') {
    os_require_access('views', $viewName);
}

$pageTitle      = 'OpenSparrow — Views';
$extraCss       = '<link href="assets/css/views.css" rel="stylesheet">';

$headerControls = '<input id="globalSearch" type="text" placeholder="'
    . htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8') . '" />'
    . '<select id="columnFilter" hidden><option value=""></option></select>'
    . '<div id="filterBar"></div>'
    . '<select id="groupBy" hidden><option value=""></option></select>'
    . os_header_clear_filters();
ob_start();
?>
<main>
    <section id="viewSection">
        <div id="viewBreadcrumb" class="vw-breadcrumb"></div>
        <div id="viewContainer" class="vw-container">
            <div class="vw-loading"><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </section>
</main>
<?php
$pageContent = ob_get_clean();

$extraScripts = os_inline_globals([
    'VIEWS_INITIAL' => $viewName ?: null,
    'CSRF_TOKEN'    => $_SESSION['csrf_token'],
], $cspNonce)
    . os_module_script('assets/js/views.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
