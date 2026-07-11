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
$tClearF        = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
$headerControls = '<div id="printFilters" class="print-filters"></div>'
    . '<button id="clearFilters" hidden title="' . $tClearF . '">' . $tClearF . '</button>';
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
ob_start();
?>
<script nonce="<?php echo $cspNonce; ?>">
    window.PRINT_INITIAL = <?php echo json_encode($printName ?: null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.CSRF_TOKEN    = <?php echo json_encode($_SESSION['csrf_token']); ?>;
</script>
<script type="module" src="assets/js/print.js?v=<?php echo @filemtime('assets/js/print.js'); ?>" nonce="<?php echo $cspNonce; ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
