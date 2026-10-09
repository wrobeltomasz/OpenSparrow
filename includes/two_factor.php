<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/mailer.php';

const TWO_FACTOR_CODE_TTL_SECONDS = 60;
const TWO_FACTOR_MAX_ATTEMPTS = 5;

function two_factor_enabled(): bool
{
    return (bool) settings_value('two_factor_enabled', false);
}

function two_factor_email_column_present(\PgSql\Connection $conn): bool
{
    static $present = null;
    if ($present === null) {
        $present = (bool) @pg_query(
            $conn,
            'SELECT email FROM ' . sys_table('users') . ' LIMIT 0'
        );
    }
    return $present;
}

function two_factor_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function two_factor_code_hash(string $code): string
{
    return secret_hash($code);
}

function two_factor_code_matches(string $submittedCode, string $storedHash): bool
{
    return hash_equals($storedHash, two_factor_code_hash($submittedCode));
}

function two_factor_send_email(string $recipient, string $code, string $username): bool
{
    $appName = 'OpenSparrow';
    $appNameRaw = settings_value('app_name', null);
    if (is_string($appNameRaw) && $appNameRaw !== '') {
        $appName = $appNameRaw;
    }
    $subject = $appName . ' — login verification code';
    $body = "Hello {$username},\n\n"
        . "Your verification code is: {$code}\n\n"
        . 'The code is valid for ' . TWO_FACTOR_CODE_TTL_SECONDS . ' seconds. '
        . "If you did not try to log in, consider changing your password.\n\n"
        . '-- ' . $appName;
    return os_send_mail_now($recipient, $subject, $body);
}

function two_factor_session_start(int $userId, string $username, string $code): void
{
    $_SESSION['pending_2fa_user_id'] = $userId;
    $_SESSION['pending_2fa_username'] = $username;
    $_SESSION['pending_2fa_code_hash'] = two_factor_code_hash($code);
    $_SESSION['pending_2fa_expires'] = time() + TWO_FACTOR_CODE_TTL_SECONDS;
    $_SESSION['pending_2fa_attempts'] = 0;
}

function two_factor_session_clear(): void
{
    unset(
        $_SESSION['pending_2fa_user_id'],
        $_SESSION['pending_2fa_username'],
        $_SESSION['pending_2fa_code_hash'],
        $_SESSION['pending_2fa_expires'],
        $_SESSION['pending_2fa_attempts']
    );
}

function two_factor_send_allowed(string $ipHash, string $username): bool
{
    require_once __DIR__ . '/rate_limit.php';
    $ipAllowed = os_rate_limit_ok('two_factor_ip_' . $ipHash, 3, 'two_factor', false);
    $userAllowed = os_rate_limit_ok('two_factor_user_' . strtolower($username), 3, 'two_factor', false);
    return $ipAllowed && $userAllowed;
}
