<?php

declare(strict_types=1);

// views.php — Custom/saved views page (frontend HTML)
// Auth gate: redirect to login if no session; admin redirected to /admin; UA/lifetime enforcement
// Generates CSRF token + CSP nonce + send_security_headers('unsafe-style'); ?view= selects the view (max 64 chars)
// Renders the saved-view UI (views.css); data via api/views.php

require_once __DIR__ . '/../includes/session.php';
start_session();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Hard session-lifetime + User-Agent enforcement (centralised in session.php).
enforce_session_redirect();

if (($_SESSION['role'] ?? 'viewer') === 'admin') {
    header('Location: admin/');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$cspNonce  = bin2hex(random_bytes(16));
$viewName  = substr($_GET['view'] ?? '', 0, 64);
$userRole  = $_SESSION['role'] ?? 'viewer';

send_security_headers($cspNonce, true, 'unsafe-style');

$pageTitle      = 'OpenSparrow — Views';
$extraCss       = '<link href="assets/css/views.css" rel="stylesheet">';
$headerControls = '<input id="globalSearch" type="text" placeholder="' . htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8') . '" />';
ob_start();
?>
<main>
    <section id="viewSection">
        <div id="viewBreadcrumb" class="vw-breadcrumb"></div>
        <div id="viewContainer" class="vw-container">
            <div class="vw-loading"><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </section>
</main>
<?php
$pageContent = ob_get_clean();
ob_start();
?>
<script nonce="<?php echo $cspNonce; ?>">
    window.VIEWS_INITIAL = <?php echo json_encode($viewName ?: null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.CSRF_TOKEN    = <?php echo json_encode($_SESSION['csrf_token']); ?>;
</script>
<script type="module" src="assets/js/views.js?v=<?php echo @filemtime('assets/js/views.js'); ?>" nonce="<?php echo $cspNonce; ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
