<?php

declare(strict_types=1);

// views.php — Custom/saved views page (frontend HTML)
// Boots via includes/bootstrap.php: os_page_bootstrap('unsafe-style' CSP) — auth gate, admin redirect, UA/lifetime enforcement, CSRF token, CSP nonce + headers
// ?view= selects the view (max 64 chars)
// Renders the saved-view UI (views.css); data via api/views.php

require_once __DIR__ . '/../includes/bootstrap.php';

$page      = os_page_bootstrap(['csp' => 'unsafe-style']);
$cspNonce  = $page['nonce'];
$userRole  = $page['role'];
$viewName  = substr($_GET['view'] ?? '', 0, 64);

$pageTitle      = 'OpenSparrow — Views';
$extraCss       = '<link href="assets/css/views.css" rel="stylesheet">';
// #globalSearch keeps its historical grid markup (type="text", extra selects) —
// Cypress and grid/keyboard.js depend on these exact ids.
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
