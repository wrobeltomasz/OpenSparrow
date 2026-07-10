<?php

// dashboard.php — Dashboard page with widgets (frontend HTML)
// Boots via includes/bootstrap.php: os_page_bootstrap('no-connect' CSP) — auth gate, admin redirect, UA/lifetime enforcement, CSRF token, CSP nonce + headers
// Exposes capability flags (canEdit/canExport) to the client instead of the raw role
// Global period filter + widget visibility chips render in the app header (via $headerControls, like the grid page)
// Renders the dashboard UI; widget definitions from dashboard.json, data via api.php

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap(['csp' => 'no-connect']);
$cspNonce = $page['nonce'];
$userRole = $page['role'];
$userCaps = $page['caps'];

$pageTitle = 'OpenSparrow | Dashboard';
$dashPeriods = [
    'all'        => 'dashboard.filter_all',
    'today'      => 'dashboard.filter_today',
    '7d'         => 'dashboard.filter_7d',
    '30d'        => 'dashboard.filter_30d',
    'this_month' => 'dashboard.filter_month',
];
$headerControls = '<label for="dashDateFilter" class="dash-filter-label">'
    . htmlspecialchars(t('dashboard.filter_label'), ENT_QUOTES, 'UTF-8') . '</label>'
    . '<select id="dashDateFilter">';
foreach ($dashPeriods as $dashVal => $dashKey) {
    $headerControls .= '<option value="' . $dashVal . '">'
        . htmlspecialchars(t($dashKey), ENT_QUOTES, 'UTF-8') . '</option>';
}
$dashClearF = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
$headerControls .= '</select><div id="dashboardFilters" class="dashboard-filters"></div>'
    . '<button id="clearFilters" hidden title="' . $dashClearF . '">' . $dashClearF . '</button>';
ob_start();
?>
<main id="dashboardMain">
    <h2 id="gridTitle">Dashboard</h2>
    <section id="dashboardSection" class="dashboard-grid"></section>
</main>
<?php
$pageContent = ob_get_clean();
ob_start();
?>
<script nonce="<?php echo $cspNonce; ?>">
    window.USER_CAPS = <?php echo json_encode($userCaps, JSON_THROW_ON_ERROR); ?>;
</script>
<script type="module" src="assets/js/dashboard.js?v=<?php echo @filemtime('assets/js/dashboard/drill-down.js'); ?>" nonce="<?php echo $cspNonce; ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
