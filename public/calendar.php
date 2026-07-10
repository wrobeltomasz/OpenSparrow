<?php

// calendar.php — Calendar page (frontend HTML)
// Boots via includes/bootstrap.php: os_page_bootstrap('no-connect' CSP) — auth gate, admin redirect, UA/lifetime enforcement, CSRF token, CSP nonce + headers
// Exposes capability flags (canEdit/canExport) to the client instead of the raw role
// Exposes CALENDAR_SOURCES (table + color per source from config/calendar.json) for the client-side filter chips
// Search box + per-source visibility chips render in the app header (via $headerControls, like the grid page)
// Renders the calendar UI (header, grid); event data and CALENDAR_MOVE handled by api.php

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap(['csp' => 'no-connect']);
$cspNonce = $page['nonce'];
$userRole = $page['role'];
$userCaps = $page['caps'];

// Expose configured calendar sources (table + color) so the client can build
// the filter bar for all sources, not only those with events in the visible month.
$calConfigPath   = __DIR__ . '/../config/calendar.json';
$calConfig       = file_exists($calConfigPath)
    ? json_decode((string)file_get_contents($calConfigPath), true)
    : [];
$calendarSources = [];
foreach (($calConfig['sources'] ?? []) as $src) {
    if (!empty($src['table'])) {
        $calendarSources[] = [
            'table' => $src['table'],
            'color' => $src['color'] ?? '#3b82f6',
        ];
    }
}

$pageTitle = 'OpenSparrow | Calendar';
$tSearchPh = htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8');
$tClearF   = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
$headerControls = '<input type="search" id="calendarSearch" placeholder="' . $tSearchPh . '"'
    . ' aria-label="' . $tSearchPh . '">'
    . '<div id="calendarFilters" class="calendar-filters"></div>'
    . '<button id="clearFilters" hidden title="' . $tClearF . '">' . $tClearF . '</button>';
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
ob_start();
?>
<script nonce="<?php echo $cspNonce; ?>">
    window.USER_CAPS = <?php echo json_encode($userCaps, JSON_THROW_ON_ERROR); ?>;
    window.CALENDAR_SOURCES = <?php echo json_encode($calendarSources, JSON_THROW_ON_ERROR); ?>;
</script>
<script type="module" src="assets/js/calendar.js?v=<?php echo @filemtime('assets/js/calendar.js'); ?>" nonce="<?php echo $cspNonce; ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
