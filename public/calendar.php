<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap(['csp' => 'no-connect']);
$cspNonce = $page['nonce'];
$userRole = $page['role'];
$userCaps = $page['caps'];

require_once __DIR__ . '/../includes/config_store.php';
$calConfig = config_get('calendar') ?? [];
$calendarSources = [];
foreach (($calConfig['sources'] ?? []) as $source) {
    if (!empty($source['table']) && !empty($source['date_column'])) {
        $calendarSources[] = [
            'table'       => $source['table'],
            'color'       => $source['color'] ?? '#3b82f6',
            'date_column' => $source['date_column'],
        ];
    }
}

$pageTitle      = 'OpenSparrow | Calendar';
$headerControls = os_header_search('calendarSearch')
    . os_header_filters('calendarFilters', 'calendar-filters')
    . os_header_clear_filters();

$calendarLabels = [
    'title' => 'Month Year',
    'prev'  => t('calendar.prev'),
    'next'  => t('calendar.next'),
];

ob_start();
include __DIR__ . '/../templates/calendar.php';
$pageContent = ob_get_clean();

$extraScripts = os_inline_globals([
    'USER_CAPS'        => $userCaps,
    'CALENDAR_SOURCES' => $calendarSources,
], $cspNonce)
    . os_module_script('assets/js/calendar.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
