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

if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > SESSION_MAX_LIFETIME) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Verify session integrity by comparing the stored User-Agent hash against the current request.
// Eliminates opportunistic session hijacking with stolen cookies from different clients.
$sessionUserAgent = $_SESSION['user_agent'] ?? null;
$currentUserAgent = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
if ($sessionUserAgent !== null && !hash_equals($sessionUserAgent, $currentUserAgent)) {
    session_destroy();
    header("Location: login.php");
    exit;
}

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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>OpenSparrow | Calendar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>" />
    <link href="assets/css/styles.css" rel="stylesheet" />
    <link href="assets/css/mobile.css" rel="stylesheet" media="only screen and (max-width: 768px)" />
</head>
<body>
<?php include 'templates/header.php'; ?>
<main id="calendarMain">
    <div class="calendar-header">
        <h2 id="calendarTitle">Month Year</h2>
        <div class="calendar-nav">
            <button id="btnPrev">Prev</button>
            <button id="btnNext">Next</button>
        </div>
    </div>

    <div id="calendarContainer" class="calendar-grid"></div>
</main>
</div>
<?php include 'templates/footer.php'; ?>
<script nonce="<?php echo $cspNonce; ?>">
    window.USER_CAPS = <?php echo json_encode($userCaps, JSON_THROW_ON_ERROR); ?>;
</script>
<script type="module" src="assets/js/calendar.js?v=<?php echo @filemtime('assets/js/calendar.js'); ?>" nonce="<?php echo $cspNonce; ?>"></script>
</body>
</html>
