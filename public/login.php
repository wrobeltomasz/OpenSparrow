<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

use App\Security\UserRole;

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap(['guest' => true, 'setup_check' => true, 'csp' => 'login', 'hsts' => false]);
$cspNonce = $page['nonce'];

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

    return 'index.php';
}

$version = 'unknown';
$versionFile = __DIR__ . '/../includes/VERSION';
if (is_file($versionFile)) {
    $versionContent = @file_get_contents($versionFile);
    if ($versionContent !== false) {
        $version = trim($versionContent);
    }
}

if (isset($_SESSION['user_id'])) {
    header("Location: " . resolve_landing_page());
    exit;
}

$loginLogoSrc = 'assets/img/logo-brown.png';
if ((bool) settings_value('logo_enabled', false)) {
    $customLogoPath = settings_value('custom_logo_path', null);
    if (is_string($customLogoPath) && $customLogoPath !== '') {
        $loginLogoSrc = $customLogoPath;
    }
}

$appNameRaw = settings_value('app_name', null);
$appName    = is_string($appNameRaw) && $appNameRaw !== '' ? $appNameRaw : 'OpenSparrow';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenPost = $_POST['csrf_token'] ?? '';
    $tokenSession = $_SESSION['csrf_token'] ?? '';

    if (!hash_equals($tokenSession, $tokenPost)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $ipHash = hash_hmac('sha256', client_ip(), IP_HASH_SALT);

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
        $error = 'Invalid credentials.';
    }

    if (empty($error)) {
        require_once __DIR__ . '/../includes/db.php';
        require __DIR__ . '/../includes/api_helpers.php';

        $conn = db_connect();

        $maxAttemptsPerIp       = LOGIN_MAX_ATTEMPTS_PER_IP;
        $maxAttemptsPerUsername = LOGIN_MAX_ATTEMPTS_PER_USERNAME;
        $lockoutMinutes         = LOGIN_LOCKOUT_MINUTES;

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

            if ((int)$row['cnt_ip'] >= $maxAttemptsPerIp) {
                $error = 'Too many failed attempts. Please try again later.';
            } elseif ((int)$row['cnt_user'] >= $maxAttemptsPerUsername) {
                $error = 'Too many failed attempts. Please try again later.';
            }
        }

        if (empty($error)) {
            $sqlUser = 'SELECT id, username, password_hash, salt, role, avatar_id FROM '
                . sys_table('users') . ' WHERE username = $1';
            $resUser = pg_query_params($conn, $sqlUser, [$username]);

            if (!$resUser) {
                $error = 'Technical error. Contact administrator.';
            } else {
                $user = pg_fetch_assoc($resUser);

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
                    $_SESSION['avatar_id'] = ($user['avatar_id'] !== '' && $user['avatar_id'] !== null)
                        ? (int)$user['avatar_id']
                        : null;
                    $_SESSION['created_at'] = time();
                    $_SESSION['user_agent'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

                    if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID, ARGON2_OPTIONS)) {
                        $newSalt = bin2hex(random_bytes(32));
                        $newHash = password_hash($newSalt . $password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
                        $sqlUpdate = 'UPDATE ' . sys_table('users')
                            . ' SET password_hash = $1, salt = $2 WHERE id = $3';
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
            <center>
                <img
                    src="<?php echo htmlspecialchars($loginLogoSrc, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="<?php echo htmlspecialchars(t('common.logo_alt'), ENT_QUOTES, 'UTF-8'); ?>"
                    class="footer-logo"
                    height="48"
                />
            </center>
            <h2><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></h2>
            <?php if ($error) : ?>
                <div class="error" data-cy="login-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"
                />
                <input
                    type="text"
                    name="username"
                    data-cy="username"
                    placeholder="<?php echo htmlspecialchars(t('auth.username'), ENT_QUOTES, 'UTF-8'); ?>"
                    required
                    autofocus
                    autocomplete="username"
                />
                <div class="password-container">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        data-cy="password"
                        placeholder="<?php echo htmlspecialchars(t('auth.password'), ENT_QUOTES, 'UTF-8'); ?>"
                        required
                        autocomplete="current-password"
                    />
                    <span id="togglePassword" class="toggle-password">
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="#888"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </span>
                </div>
                <button type="submit" data-cy="loginBtn">
                    <?php echo htmlspecialchars(t('auth.login'), ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </form>
            <div class="login-info">
                <span>v<?php echo htmlspecialchars($version); ?></span>
                <span class="login-info-separator">·</span>
                <a
                    href="https://github.com/wrobeltomasz/OpenSparrow"
                    target="_blank"
                    rel="noopener noreferrer"
                >GitHub</a>
            </div>
        </div>
    </div>
    <script
        src="assets/js/login.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/login.js'); ?>"
        nonce="<?php echo $cspNonce; ?>"
    ></script>
    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
