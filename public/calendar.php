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
foreach (($calConfig['sources'] ?? []) as $src) {
    if (!empty($src['table']) && !empty($src['date_column'])) {
        $calendarSources[] = [
            'table'       => $src['table'],
            'color'       => $src['color'] ?? '#3b82f6',
            'date_column' => $src['date_column'],
        ];
    }
}

$pageTitle      = 'OpenSparrow | Calendar';
$headerControls = os_header_search('calendarSearch')
    . os_header_filters('calendarFilters', 'calendar-filters')
    . os_header_clear_filters();
ob_start();
?>
<main id="calendarMain">
    <div class="calendar-header">
        <h2 id="calendarTitle">Month Year</h2>
        <div class="calendar-nav">
            <button id="btnPrev"><?= t('calendar.prev') ?></button>
            <button id="btnNext"><?= t('calendar.next') ?></button>
        </div>
    </div>

    <div id="calendarContainer" class="calendar-grid"></div>
</main>
<?php
$pageContent = ob_get_clean();

$extraScripts = os_inline_globals([
    'USER_CAPS'        => $userCaps,
    'CALENDAR_SOURCES' => $calendarSources,
], $cspNonce)
    . os_module_script('assets/js/calendar.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
