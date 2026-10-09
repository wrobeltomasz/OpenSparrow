<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

use App\Exception\ControlFlowException;
use App\Exception\ForbiddenException;
use App\Exception\RedirectException;
use App\Security\UserRole;

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap(['guest' => true, 'setup_check' => true, 'csp' => 'login', 'hsts' => false]);
$cspNonce = $page['nonce'];

function resolve_landing_page(): string
{
    require_once __DIR__ . '/../includes/config_store.php';
    $isHidden = static function (string $configKey): bool {
        try {
            $config = config_get($configKey);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $exception) {
            return false;
        }
        return is_array($config) && !empty($config['hidden']);
    };

    if (!$isHidden('dashboard')) {
        return 'dashboard.php';
    }
    if (!$isHidden('calendar')) {
        return 'calendar.php';
    }

    return 'index.php';
}

if (isset($_SESSION['user_id'])) {
    throw new RedirectException(resolve_landing_page());
}

require_once __DIR__ . '/../includes/two_factor.php';

$pendingTwoFactorUserId = os_session_get('pending_2fa_user_id');

$loginLogoPath = 'assets/img/logo.png';
if ((bool) settings_value('logo_enabled', false)) {
    $customLogoPath = settings_value('custom_logo_path', null);
    if (is_string($customLogoPath) && $customLogoPath !== '') {
        $loginLogoPath = $customLogoPath;
    }
}

$appNameRaw = settings_value('app_name', null);
$appName    = is_string($appNameRaw) && $appNameRaw !== '' ? $appNameRaw : 'OpenSparrow';

$error = '';

$request = os_request();

if ($request->isPost()) {
    $tokenPost = (string) $request->post('csrf_token');
    $tokenSession = (string) os_session_get('csrf_token', '');

    if (!hash_equals($tokenSession, $tokenPost)) {
        throw new ForbiddenException('Invalid CSRF token.');
    }

    $ipHash = hash_hmac('sha256', client_ip(), IP_HASH_SALT);

    $username = '';
    $password = '';
    if ($pendingTwoFactorUserId === null) {
        $username = trim((string) $request->post('username'));
        $password = (string) $request->post('password');

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            $error = 'Invalid credentials.';
        }
    }

    if (empty($error)) {
        require_once __DIR__ . '/../includes/db.php';
        require __DIR__ . '/../includes/api_helpers.php';

        $conn = db_connect();

        $maxAttemptsPerIp       = LOGIN_MAX_ATTEMPTS_PER_IP;
        $maxAttemptsPerUsername = LOGIN_MAX_ATTEMPTS_PER_USERNAME;
        $lockoutMinutes         = LOGIN_LOCKOUT_MINUTES;
        $rateLimitWindowMinutes = LOGIN_RATE_LIMIT_WINDOW_MINUTES;
        $lookbackMinutes        = $lockoutMinutes + $rateLimitWindowMinutes;
        $attemptsTable          = sys_table('login_attempts');

        $lockoutUsername = $pendingTwoFactorUserId !== null
            ? (string) os_session_get('pending_2fa_username', '')
            : $username;

        $sqlCheck = "
            SELECT
                (SELECT EXTRACT(EPOCH FROM MAX(attempted_at)) FROM {$attemptsTable}
                  WHERE ip_hash = \$1
                    AND attempted_at > now() - (\$3 * interval '1 minute')) AS newest_ip,
                (SELECT EXTRACT(EPOCH FROM attempted_at) FROM {$attemptsTable}
                  WHERE ip_hash = \$1
                    AND attempted_at > now() - (\$3 * interval '1 minute')
                  ORDER BY attempted_at DESC OFFSET \$4 LIMIT 1) AS nth_ip,
                (SELECT EXTRACT(EPOCH FROM MAX(attempted_at)) FROM {$attemptsTable}
                  WHERE username = \$2
                    AND attempted_at > now() - (\$3 * interval '1 minute')) AS newest_username,
                (SELECT EXTRACT(EPOCH FROM attempted_at) FROM {$attemptsTable}
                  WHERE username = \$2
                    AND attempted_at > now() - (\$3 * interval '1 minute')
                  ORDER BY attempted_at DESC OFFSET \$5 LIMIT 1) AS nth_username,
                EXTRACT(EPOCH FROM now()) AS now_epoch
        ";
        $checkResult = pg_query_params($conn, $sqlCheck, [
            $ipHash,
            $lockoutUsername,
            $lookbackMinutes,
            $maxAttemptsPerIp - 1,
            $maxAttemptsPerUsername - 1,
        ]);

        $isLockedOut = false;
        if (!$checkResult) {
            $error = 'Technical error. Contact administrator.';
        } else {
            $row = pg_fetch_assoc($checkResult);
            $nowEpoch = (float) ($row['now_epoch'] ?? 0);

            $isLockedOut = static function (
                mixed $newest,
                mixed $nth
            ) use (
                $nowEpoch,
                $rateLimitWindowMinutes,
                $lockoutMinutes
            ): bool {
                if ($nth === null || $newest === null) {
                    return false;
                }
                $newestEpoch = (float) $newest;
                $burstSeconds = $newestEpoch - (float) $nth;
                return $burstSeconds <= $rateLimitWindowMinutes * 60
                    && ($nowEpoch - $newestEpoch) < $lockoutMinutes * 60;
            };

            if ($isLockedOut($row['newest_ip'] ?? null, $row['nth_ip'] ?? null)) {
                $error = 'Too many failed attempts. Please try again later.';
            } elseif ($isLockedOut($row['newest_username'] ?? null, $row['nth_username'] ?? null)) {
                $error = 'Too many failed attempts. Please try again later.';
            }
        }

        if (empty($error) && $pendingTwoFactorUserId !== null) {
            $pendingUserId = (int) $pendingTwoFactorUserId;
            $pendingCodeHash = (string) os_session_get('pending_2fa_code_hash', '');
            $pendingExpires = (int) os_session_get('pending_2fa_expires', 0);
            $pendingAttempts = (int) os_session_get('pending_2fa_attempts', 0);
            $pendingUsername = (string) os_session_get('pending_2fa_username', '');

            $submittedCode = trim((string) $request->post('two_factor_code'));
            if (!preg_match('/^[0-9]{6}$/', $submittedCode)) {
                $error = 'Invalid verification code.';
            } elseif ($pendingExpires < time()) {
                two_factor_session_clear();
                $pendingTwoFactorUserId = null;
                $error = 'Verification code expired. Please log in again.';
            } elseif (!two_factor_code_matches($submittedCode, $pendingCodeHash)) {
                $_SESSION['pending_2fa_attempts'] = $pendingAttempts + 1;
                if ($pendingAttempts + 1 >= TWO_FACTOR_MAX_ATTEMPTS) {
                    two_factor_session_clear();
                    $pendingTwoFactorUserId = null;
                }
                $sqlInsert = 'INSERT INTO ' . sys_table('login_attempts') . ' (username, ip_hash) VALUES ($1, $2)';
                pg_query_params($conn, $sqlInsert, [$pendingUsername, $ipHash]);
                $error = 'Invalid verification code.';
            } else {
                $sqlUser = 'SELECT id, username, role, avatar_id FROM '
                    . sys_table('users') . ' WHERE id = $1';
                $userResult = pg_query_params($conn, $sqlUser, [$pendingUserId]);

                if (!$userResult || !($user = pg_fetch_assoc($userResult))) {
                    two_factor_session_clear();
                    $pendingTwoFactorUserId = null;
                    $error = 'Invalid credentials.';
                } else {
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

                    two_factor_session_clear();

                    log_user_action($conn, $user['id'], 'LOGIN');

                    session_write_close();

                    throw new RedirectException(
                        UserRole::fromSession() === UserRole::Admin ? 'admin/' : resolve_landing_page()
                    );
                }
            }
        } elseif (empty($error) && $pendingTwoFactorUserId === null) {
            $sqlUser = 'SELECT id, username, password_hash, salt, role, avatar_id FROM '
                . sys_table('users') . ' WHERE username = $1';
            $userResult = pg_query_params($conn, $sqlUser, [$username]);

            if (!$userResult) {
                $error = 'Technical error. Contact administrator.';
            } else {
                $user = pg_fetch_assoc($userResult);

                if (!$user) {
                    password_hash($password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
                }

                $storedSalt = $user['salt'] ?? '';
                $toVerify = $storedSalt !== '' ? $storedSalt . $password : $password;

                if ($user && password_verify($toVerify, $user['password_hash'])) {
                    $userEmail = '';
                    if (two_factor_enabled() && two_factor_email_column_present($conn)) {
                        $emailResult = pg_query_params(
                            $conn,
                            'SELECT email FROM ' . sys_table('users') . ' WHERE id = $1',
                            [$user['id']]
                        );
                        if ($emailResult) {
                            $emailRow = pg_fetch_assoc($emailResult);
                            $userEmail = trim((string) ($emailRow['email'] ?? ''));
                        }
                    }

                    $twoFactorApplies = $userEmail !== ''
                        && filter_var($userEmail, FILTER_VALIDATE_EMAIL) !== false;

                    if ($twoFactorApplies && !two_factor_send_allowed($ipHash, $user['username'])) {
                        $error = 'Too many verification code requests. Please try again later.';
                    } elseif ($twoFactorApplies) {
                        $verificationCode = two_factor_generate_code();
                        two_factor_session_start((int) $user['id'], $user['username'], $verificationCode);

                        if (two_factor_send_email($userEmail, $verificationCode, $user['username'])) {
                            $pendingTwoFactorUserId = (int) $user['id'];
                            session_write_close();
                        } else {
                            two_factor_session_clear();
                            $error = 'Could not send the verification email. Contact administrator.';
                        }
                    } else {
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

                        throw new RedirectException(
                            UserRole::fromSession() === UserRole::Admin ? 'admin/' : resolve_landing_page()
                        );
                    }
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
<html lang="<?php echo htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8'); ?>">
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
                    src="<?php echo htmlspecialchars($loginLogoPath, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="<?php echo htmlspecialchars(t('common.logo_alt'), ENT_QUOTES, 'UTF-8'); ?>"
                    class="footer-logo"
                    height="48"
                />
            </center>
            <h2><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></h2>
            <?php if ($error) : ?>
                <div class="error" data-cy="login-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($pendingTwoFactorUserId !== null) : ?>
                <p class="login-2fa-hint"><?php echo htmlspecialchars(t('auth.two_factor_sent'), ENT_QUOTES, 'UTF-8'); ?></p>
                <form method="POST">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars((string) os_session_get('csrf_token'), ENT_QUOTES, 'UTF-8'); ?>"
                    />
                    <input
                        type="text"
                        name="two_factor_code"
                        data-cy="two-factor-code"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        placeholder="<?php echo htmlspecialchars(t('auth.two_factor_code'), ENT_QUOTES, 'UTF-8'); ?>"
                        required
                        autofocus
                        autocomplete="one-time-code"
                    />
                    <button type="submit" data-cy="verifyBtn">
                        <?php echo htmlspecialchars(t('auth.two_factor_verify'), ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                </form>
            <?php else : ?>
                <form method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars((string) os_session_get('csrf_token'), ENT_QUOTES, 'UTF-8'); ?>"
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
                <label class="login-language" for="languageSelect">
                    <?php echo htmlspecialchars(t('auth.language'), ENT_QUOTES, 'UTF-8'); ?>
                </label>
                <select id="languageSelect" name="language" data-cy="languageSelect">
                    <?php foreach (I18n::availableLanguageMeta() as $localeCode => $meta) : ?>
                        <option
                            value="<?php echo htmlspecialchars($localeCode, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $localeCode === I18n::locale() ? 'selected' : ''; ?>
                        >
                            <?php echo htmlspecialchars($meta['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" data-cy="loginBtn">
                    <?php echo htmlspecialchars(t('auth.login'), ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <script
        src="assets/js/login.js?v=<?php echo asset_version(__DIR__ . '/assets/js/login.js'); ?>"
        nonce="<?php echo $cspNonce; ?>"
    ></script>
    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
