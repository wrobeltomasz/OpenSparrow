<?php
require __DIR__ . '/includes/config.php';

// Set secure session cookie parameters before starting the session
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => SECURE_COOKIES,
    'httponly' => true,
    'samesite' => SESSION_SAMESITE,
]);
session_start();

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

// Apply essential security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Strict-Transport-Security: max-age=" . HSTS_MAX_AGE . "; includeSubDomains");
// style-src uses nonce instead of unsafe-inline to prevent CSS injection attacks
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-$cspNonce'; script-src 'self' 'nonce-$cspNonce'");

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
    <style nonce="<?php echo $cspNonce; ?>">
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .calendar-nav button {
            padding: 5px 15px;
            cursor: pointer;
            background: var(--panel, #fff);
            border: 1px solid var(--border, #ccc);
            border-radius: 4px;
        }
        .calendar-nav button:hover {
            background: var(--border-light, #f1f5f9);
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--border-light, #e2e8f0);
            border: 1px solid var(--border-light, #e2e8f0);
            border-radius: 4px;
        }
        .calendar-day-name {
            background: #f8fafc;
            padding: 10px;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
        }
        .calendar-cell {
            background: #fff;
            min-height: 120px;
            padding: 5px;
            display: flex;
            flex-direction: column;
        }
        .calendar-cell.empty {
            background: #f8fafc;
        }
        .calendar-date-num {
            font-size: 14px;
            font-weight: bold;
            color: #64748b;
            margin-bottom: 5px;
            text-align: right;
        }
        .calendar-event {
            font-size: 12px;
            padding: 4px 6px;
            margin-bottom: 4px;
            border-radius: 4px;
            color: white;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .calendar-event:hover {
            opacity: 0.9;
        }
        /* Inline style attributes are not covered by nonce — moved to a nonce-protected style block */
        #calendarMain { padding: 20px; width: 100%; overflow-y: auto; }
    </style>
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
