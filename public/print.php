<?php

declare(strict_types=1);

// print.php — Printable reports page (frontend HTML), mirrors views.php
// Boots via includes/bootstrap.php: os_page_bootstrap (default strict CSP) — auth gate,
// UA/lifetime enforcement, CSRF token, CSP nonce + headers
// ?print= selects the print template (max 64 chars)
// Renders the print-template UI (print.css); data via api/print.php; window.print() to print
// Report parameter selects render in the blue app header (via $headerControls, like the grid
// page) into #printFilters — populated by print.js once the template's params are known

require_once __DIR__ . '/../includes/bootstrap.php';

$page      = os_page_bootstrap();
$cspNonce  = $page['nonce'];
$printName = substr($_GET['print'] ?? '', 0, 64);

$pageTitle      = 'OpenSparrow — Print';
$extraCss       = '<link href="assets/css/print.css" rel="stylesheet">';
$headerControls = os_header_filters('printFilters', 'print-filters')
    . os_header_clear_filters();
ob_start();
?>
<main>
    <section id="printSection">
        <div id="printContainer" class="pr-container">
            <div class="pr-loading"><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </section>
</main>
<?php
$pageContent = ob_get_clean();

$extraScripts = os_inline_globals([
    'PRINT_INITIAL' => $printName ?: null,
    'CSRF_TOKEN'    => $_SESSION['csrf_token'],
], $cspNonce)
    . os_module_script('assets/js/print.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
