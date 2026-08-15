<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../includes/bootstrap.php';

$page      = os_page_bootstrap(['csp' => 'no-connect']);
$cspNonce  = $page['nonce'];
$userRole  = $page['role'];
$userCaps  = $page['caps'];
$boardId   = substr($_GET['board'] ?? '', 0, 64);

if ($boardId !== '') {
    os_require_access('boards', $boardId);
}

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
