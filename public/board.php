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
$boardId   = substr(os_query_string('board'), 0, 64);

if ($boardId !== '') {
    os_require_access('boards', $boardId);
}

$pageTitle      = 'OpenSparrow | Board';
$headerControls = os_header_search('boardSearch')
    . os_header_filters('boardFilters', 'board-filters')
    . os_header_clear_filters();

$boardLabels = [
    'title'   => t('board.title'),
    'loading' => t('common.loading'),
];

ob_start();
include __DIR__ . '/../templates/board.php';
$pageContent = ob_get_clean();

$extraScripts = os_inline_globals(['USER_CAPS' => $userCaps, 'BOARD_INITIAL' => $boardId ?: null], $cspNonce)
    . os_module_script('assets/js/board.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
