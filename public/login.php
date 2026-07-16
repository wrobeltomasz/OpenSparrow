<?php

// login.php — Login page and post-login landing resolver
// Boots via includes/bootstrap.php: os_page_bootstrap(guest, setup check, 'login' CSP, no HSTS) — redirects to setup.php if config/database.json is missing
// POST authenticates against sys_table('users') with password_verify (Argon2) + CSRF; brute-force throttling via sys_table('login_attempts') (per username + IP hash)
// resolve_landing_page() walks the sidebar order (dashboard -> calendar -> files -> first table), skipping modules hidden in their JSON config; reads includes/VERSION

use App\Security\UserRole;

require_once __DIR__ . '/../includes/bootstrap.php';

// Guest page: setup check, CSRF token, CSP nonce + 'login' headers (no HSTS) — no auth gate
$page     = os_page_bootstrap(['guest' => true, 'setup_check' => true, 'csp' => 'login', 'hsts' => false]);
$cspNonce = $page['nonce'];

// Resolve the landing page after login by walking the sidebar order.
// When an administrator hides a module from the sidebar (hidden: true in
// the matching JSON config), we skip it so the user lands on the first
// item that is actually visible in the navigation. Order mirrors the
// prepend sequence in assets/js/app.js: Dashboard, Calendar, Files,
// then the first table in the schema.
function resolve_landing_page(): string
{
    require_once __DIR__ . '/../includes/config_store.php';
    $isHidden = static function (string $configKey): bool {
        try {
            $cfg = config_get($configKey);
        } catch (Throwable $e) {
            return false;
        }
        return is_array($cfg) && !empty($cfg['hidden']);
    };

    if (!$isHidden('dashboard')) {
        return 'dashboard.php';
    }
    if (!$isHidden('calendar')) {
        return 'calendar.php';
    }
    // Files module is always visible; index.php renders the first
    // non-hidden table for any remaining case.
    return 'index.php';
}

// Read application version
$version = 'unknown';
$versionFile = __DIR__ . '/../includes/VERSION';
if (is_file($versionFile)) {
    $versionContent = @file_get_contents($versionFile);
    if ($versionContent !== false) {
        $version = trim($versionContent);
    }
}

// Redirect if already authenticated
if (isset($_SESSION['user_id'])) {
    header("Location: " . resolve_landing_page());
    exit;
}

// Custom logo (Admin -> Settings -> Custom Logo) replaces the default login branding when enabled.
$loginLogoSrc = 'assets/img/logo-brown.png';
if ((bool) settings_value('logo_enabled', false)) {
    $customLogoPath = settings_value('custom_logo_path', null);
    if (is_string($customLogoPath) && $customLogoPath !== '') {
        $loginLogoSrc = $customLogoPath;
    }
}

// Custom application name (Admin -> Settings -> Custom Logo) replaces the "OpenSparrow" heading.
$appNameRaw = settings_value('app_name', null);
$appName    = is_string($appNameRaw) && $appNameRaw !== '' ? $appNameRaw : 'OpenSparrow';

$error = '';

// Process authentication request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenPost = $_POST['csrf_token'] ?? '';
    $tokenSession = $_SESSION['csrf_token'] ?? '';

    // Validate CSRF token using timing attack safe comparison
    if (!hash_equals($tokenSession, $tokenPost)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $ipHash = hash_hmac('sha256', client_ip(), IP_HASH_SALT);

    // Basic input validation
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
        $error = 'Invalid credentials.';
    }

    if (empty($error)) {
        require __DIR__ . '/../includes/db.php';
        require __DIR__ . '/../includes/api_helpers.php';

        $conn = db_connect();

        $maxAttemptsPerIp       = LOGIN_MAX_ATTEMPTS_PER_IP;
        $maxAttemptsPerUsername = LOGIN_MAX_ATTEMPTS_PER_USERNAME;
        $lockoutMinutes         = LOGIN_LOCKOUT_MINUTES;

        // Check rate limit by IP hash and username in a single round-trip.
        // The OR condition combined with two conditional SUMs lets PostgreSQL
        // use both indexes (idx_spw_login_attempts_ip, idx_spw_login_attempts_username)
        // and return both counters at once.
        $sqlCheck = "
            SELECT
                SUM(CASE WHEN ip_hash  = \$1 THEN 1 ELSE 0 END) AS cnt_ip,
                SUM(CASE WHEN username = \$2 THEN 1 ELSE 0 END) AS cnt_user
            FROM " . sys_table('login_attempts') . "
            WHERE attempted_at > now() - (\$3 * interval '1 minute')
              AND (ip_hash = \$1 OR username = \$2)
        ";
        $resCheck = pg_query_params($conn, $sqlCheck, [$ipHash, $username, $lockoutMinutes]);

        if (!$resCheck) {
            $error = 'Technical error. Contact administrator.';
        } else {
            $row = pg_fetch_assoc($resCheck);

            // Both limits are evaluated independently — intentionally the same
            // generic message to avoid leaking which criterion triggered the block
            if ((int)$row['cnt_ip'] >= $maxAttemptsPerIp) {
                $error = 'Too many failed attempts. Please try again later.';
            } elseif ((int)$row['cnt_user'] >= $maxAttemptsPerUsername) {
                $error = 'Too many failed attempts. Please try again later.';
            }
        }

        if (empty($error)) {
            $sqlUser = 'SELECT id, username, password_hash, salt, role, avatar_id FROM ' . sys_table('users') . ' WHERE username = $1';
            $resUser = pg_query_params($conn, $sqlUser, [$username]);

            if (!$resUser) {
                $error = 'Technical error. Contact administrator.';
            } else {
                $user = pg_fetch_assoc($resUser);

                // Equalise response time whether or not the username exists, so an
                // attacker cannot enumerate valid usernames by timing the login.
                // (password_verify is skipped for a missing user, so without this a
                // non-existent account returns measurably faster.)
                if (!$user) {
                    password_hash($password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
                }

                $storedSalt = $user['salt'] ?? '';
                $toVerify = $storedSalt !== '' ? $storedSalt . $password : $password;

                if ($user && password_verify($toVerify, $user['password_hash'])) {
                    session_regenerate_id(true);

                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'] ?? 'editor';
                    $_SESSION['avatar_id'] = ($user['avatar_id'] !== '' && $user['avatar_id'] !== null) ? (int)$user['avatar_id'] : null;
                    $_SESSION['created_at'] = time();
                    $_SESSION['user_agent'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

                    // Rehash on login if parameters changed; generate new salt when rehashing
                    if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID, ARGON2_OPTIONS)) {
                        $newSalt = bin2hex(random_bytes(32));
                        $newHash = password_hash($newSalt . $password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
                        $sqlUpdate = 'UPDATE ' . sys_table('users') . ' SET password_hash = $1, salt = $2 WHERE id = $3';
                        pg_query_params($conn, $sqlUpdate, [$newHash, $newSalt, $user['id']]);
                    }

                    log_user_action($conn, $user['id'], 'LOGIN');

                    session_write_close();

                    if (UserRole::fromSession() === UserRole::Admin) {
                        header("Location: admin/");
                        exit;
                    }
                    header("Location: " . resolve_landing_page());
                    exit;
                } else {
                    // Log failed attempt
                    $sqlInsert = 'INSERT INTO ' . sys_table('login_attempts') . ' (username, ip_hash) VALUES ($1, $2)';
                    pg_query_params($conn, $sqlInsert, [$username, $ipHash]);
                    $error = 'Invalid credentials.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?> | Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="assets/css/styles.css" rel="stylesheet" /> 
</head>
<body class="login-page">
    <div class="login-wrapper">
        <div class="login-box" data-cy="login-box">
            <center><img src="<?php echo htmlspecialchars($loginLogoSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="footer-logo" height="48" /></center>
            <h2><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></h2>
            <?php if ($error) : ?>
                <div class="error" data-cy="login-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
                <input type="text" name="username" data-cy="username" placeholder="Login" required autofocus autocomplete="username" />
                <div class="password-container">
                    <input type="password" id="password" name="password" data-cy="password" placeholder="Password" required autocomplete="current-password" />
                    <span id="togglePassword" class="toggle-password">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </span>
                </div>
                <button type="submit" data-cy="loginBtn">Submit</button>
            </form>
            <div class="login-info">
                <span>v<?php echo htmlspecialchars($version); ?></span>
                <span class="login-info-separator">·</span>
                <a href="https://github.com/wrobeltomasz/OpenSparrow" target="_blank" rel="noopener noreferrer">GitHub</a>
            </div>
        </div>
    </div>
    <script nonce="<?php echo $cspNonce; ?>">
    const passwordInput = document.getElementById("password");
    const togglePassword = document.getElementById("togglePassword");

    const iconEyeOpen = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const iconEyeClosed = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    togglePassword.addEventListener("click", () => {
        if (passwordInput.type === "password") {
            passwordInput.type = "text";
            togglePassword.innerHTML = iconEyeClosed;
        } else {
            passwordInput.type = "password";
            togglePassword.innerHTML = iconEyeOpen;
        }
    });
    </script>
    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
