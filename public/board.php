<?php

// board.php — Kanban board page (frontend HTML)
// Boots via includes/bootstrap.php: os_page_bootstrap('no-connect' CSP) — auth gate, admin redirect, UA/lifetime enforcement, CSRF token, CSP nonce + headers
// Exposes capability flags (canEdit/canExport) to the client instead of the raw role
// Renders the board UI; card data and BOARD_MOVE handled by api.php
// Search box + filter chips render in the app header (via $headerControls, like the grid page):
//   - chips: per-lane visibility (built from the board's status column values), state persisted in localStorage
//   - search: client-side phrase filter — hides cards whose title/fields/id do not contain the typed text

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap(['csp' => 'no-connect']);
$cspNonce = $page['nonce'];
$userRole = $page['role'];
$userCaps = $page['caps'];

$pageTitle = 'OpenSparrow | Board';
$tSearchPh = htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8');
$tClearF   = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
$headerControls = '<input type="search" id="boardSearch" placeholder="' . $tSearchPh . '"'
    . ' aria-label="' . $tSearchPh . '">'
    . '<div id="boardFilters" class="board-filters"></div>'
    . '<button id="clearFilters" hidden title="' . $tClearF . '">' . $tClearF . '</button>';
ob_start();
?>
<main id="boardMain">
    <div class="board-header">
        <h2 id="boardTitle"><?= htmlspecialchars(t('board.title'), ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="board-meta" id="boardMeta"></div>
    </div>

    <div id="boardContainer" class="board-grid">
        <div class="board-loading"><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</main>
<?php
$pageContent = ob_get_clean();
ob_start();
?>
<script nonce="<?php echo $cspNonce; ?>">
    window.USER_CAPS = <?php echo json_encode($userCaps, JSON_THROW_ON_ERROR); ?>;
</script>
<script type="module" src="assets/js/board.js?v=<?php echo @filemtime('assets/js/board.js'); ?>" nonce="<?php echo $cspNonce; ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
