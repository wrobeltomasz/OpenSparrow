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
$tClearF = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
$headerControls = '<input id="globalSearch" type="text" placeholder="'
    . htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8') . '" />'
    . '<select id="columnFilter" hidden><option value=""></option></select>'
    . '<div id="filterBar"></div>'
    . '<select id="groupBy" hidden><option value=""></option></select>'
    . '<button id="clearFilters" hidden title="' . $tClearF . '">' . $tClearF . '</button>';
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
ob_start();
?>
<script nonce="<?php echo $cspNonce; ?>">
    window.VIEWS_INITIAL = <?php echo json_encode($viewName ?: null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.CSRF_TOKEN    = <?php echo json_encode($_SESSION['csrf_token']); ?>;
</script>
<script type="module" src="assets/js/views.js?v=<?php echo @filemtime('assets/js/views.js'); ?>" nonce="<?php echo $cspNonce; ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
