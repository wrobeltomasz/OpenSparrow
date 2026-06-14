<?php
require_once __DIR__ . '/includes/session.php';
start_session();

// Redirect to login if not authenticated
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

// Define strict user role
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

$pageTitle = 'OpenSparrow | Board';
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
ob_start();
?>
<script nonce="<?php echo $cspNonce; ?>">
    window.USER_CAPS = <?php echo json_encode($userCaps, JSON_THROW_ON_ERROR); ?>;
</script>
<script type="module" src="assets/js/board.js?v=<?php echo @filemtime('assets/js/board.js'); ?>" nonce="<?php echo $cspNonce; ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/templates/layout.php';
