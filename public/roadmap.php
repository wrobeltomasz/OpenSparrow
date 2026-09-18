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
$roadmapId = substr(os_query_string('roadmap'), 0, 64);

if ($roadmapId !== '') {
    os_require_access('roadmaps', $roadmapId);
}

$pageTitle      = 'OpenSparrow | Roadmap';
$headerControls = os_header_search('roadmapSearch')
    . os_header_filters('roadmapFilters', 'roadmap-filters')
    . os_header_clear_filters();

$roadmapLabels = [
    'title'   => t('roadmap.title'),
    'loading' => t('common.loading'),
];

require_once __DIR__ . '/../includes/config_store.php';

$roadmapsConfig = config_get('roadmap') ?? [];
$roadmapSelect  = [];
foreach (filter_by_user_access('roadmaps', $roadmapsConfig['roadmaps'] ?? []) as $roadmapItem) {
    $roadmapNotConfigured = empty($roadmapItem['table'])
        || empty($roadmapItem['start_column'])
        || empty($roadmapItem['end_column']);
    if ($roadmapNotConfigured || !empty($roadmapItem['hidden'])) {
        continue;
    }
    if (!user_can_access_table((string) $roadmapItem['table'])) {
        continue;
    }
    $roadmapSelect[] = [
        'id'        => (string) ($roadmapItem['id'] ?? ''),
        'menu_name' => (string) ($roadmapItem['menu_name'] ?? 'Roadmap'),
    ];
}

ob_start();
include __DIR__ . '/../templates/roadmap.php';
$pageContent = ob_get_clean();

$extraScripts = os_inline_globals(
    ['USER_CAPS' => $userCaps, 'ROADMAP_INITIAL' => $roadmapId ?: null, 'ROADMAP_LIST' => $roadmapSelect],
    $cspNonce
) . os_module_script('assets/js/roadmap.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
