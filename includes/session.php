<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/i18n.php';

function start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => SECURE_COOKIES,
            'httponly' => true,
            'samesite' => SESSION_SAMESITE,
        ]);
        session_start();
        I18n::init();
    }
}

function send_security_headers(
    string $cspNonce = '',
    bool $includeHsts = true,
    string $cspMode = 'default'
): void {
    header_remove('X-Powered-By');

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if ($includeHsts) {
        header('Strict-Transport-Security: max-age=' . HSTS_MAX_AGE . '; includeSubDomains');
    }

    $nonce = $cspNonce !== '' ? " 'nonce-{$cspNonce}'" : '';

    header('Content-Security-Policy: ' . match ($cspMode) {
        'download'     => "default-src 'none'",

        'login'        => "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'{$nonce}",

        'no-connect'   => "default-src 'self'; style-src 'self'{$nonce}; script-src 'self'{$nonce}",

        'unsafe-style' => "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'{$nonce};"
            . " connect-src 'self'",
        default        => "default-src 'self'; style-src 'self'{$nonce}; script-src 'self'{$nonce}; connect-src 'self'",
    });
}

function session_touch(): void
{
    if (SESSION_IDLE_TIMEOUT > 0 && !empty($_SESSION['user_id'])) {
        $_SESSION['last_seen_at'] = time();
    }
}

function session_is_stale(): bool
{
    if (isset($_SESSION['created_at']) && (time() - (int) $_SESSION['created_at']) > SESSION_MAX_LIFETIME) {
        return true;
    }
    if (
        SESSION_IDLE_TIMEOUT > 0 && isset($_SESSION['last_seen_at'])
        && (time() - (int) $_SESSION['last_seen_at']) > SESSION_IDLE_TIMEOUT
    ) {
        return true;
    }
    $bound = $_SESSION['user_agent'] ?? null;
    if ($bound !== null) {
        $current = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        if (!hash_equals($bound, $current)) {
            return true;
        }
    }
    return false;
}

function enforce_session_json(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    if (session_is_stale()) {
        session_destroy();
        throw new \App\Exception\UnauthorizedException('Session expired', ['error' => 'Session expired']);
    }
    session_touch();
}

function enforce_session_redirect(string $loginUrl = 'login.php'): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    if (session_is_stale()) {
        session_destroy();
        throw new \App\Exception\RedirectException($loginUrl);
    }
    session_touch();
}
