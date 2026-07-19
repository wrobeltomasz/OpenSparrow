<?php

// board.php — Kanban board page (frontend HTML)
// Boots via includes/bootstrap.php: os_page_bootstrap('no-connect' CSP) — auth gate, admin redirect, UA/lifetime enforcement, CSRF token, CSP nonce + headers
// Exposes capability flags (canEdit/canExport) to the client instead of the raw role
// ?board= selects which configured board to show (max 64 chars); multiple boards can be
// configured in the admin panel, each appearing as its own sidebar menu item (templates/menu.php)
// Renders the board UI; card data and BOARD_MOVE handled by api.php
// Search box + filter chips render in the app header (via $headerControls, like the grid page):
//   - chips: per-lane visibility (built from the board's status column values), state persisted in localStorage
//   - search: client-side phrase filter — hides cards whose title/fields/id do not contain the typed text

require_once __DIR__ . '/../includes/bootstrap.php';

$page      = os_page_bootstrap(['csp' => 'no-connect']);
$cspNonce  = $page['nonce'];
$userRole  = $page['role'];
$userCaps  = $page['caps'];
$boardId   = substr($_GET['board'] ?? '', 0, 64);

$pageTitle      = 'OpenSparrow | Board';
$headerControls = os_header_search('boardSearch')
    . os_header_filters('boardFilters', 'board-filters')
    . os_header_clear_filters();
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

$extraScripts = os_inline_globals(['USER_CAPS' => $userCaps, 'BOARD_INITIAL' => $boardId ?: null], $cspNonce)
    . os_module_script('assets/js/board.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
