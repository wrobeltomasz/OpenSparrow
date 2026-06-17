<?php

// dashboard.php — Dashboard page with widgets (frontend HTML)
// Auth gate: redirect to login if no session; admin redirected to /admin; UA/lifetime enforcement
// Generates CSP nonce + send_security_headers('no-connect'); exposes capability flags (canEdit/canExport) to the client instead of the raw role
// Renders the dashboard UI; widget definitions from dashboard.json, data via api.php

require_once __DIR__ . '/../includes/session.php';
start_session();

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['role'] ?? 'viewer') === 'admin') {
    header("Location: admin/");
    exit;
}

// Hard session-lifetime + User-Agent enforcement (centralised in session.php).
enforce_session_redirect();

// Generate a unique nonce for Content Security Policy
$cspNonce = bin2hex(random_bytes(16));

send_security_headers($cspNonce, true, 'no-connect');

// Retrieve user role with a safe fallback
$userRole = $_SESSION['role'] ?? 'viewer';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Expose only capability flags to the client instead of the raw role name
// to reduce attack surface during reconnaissance
$userCaps = [
    'canEdit'   => $userRole === 'editor',
    'canExport' => in_array($userRole, ['editor', 'export'], true),
];

$pageTitle = 'OpenSparrow | Dashboard';
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
