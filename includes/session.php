<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

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
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if ($includeHsts) {
        header('Strict-Transport-Security: max-age=' . HSTS_MAX_AGE . '; includeSubDomains');
    }

    $nonce = $cspNonce !== '' ? " 'nonce-{$cspNonce}'" : '';

    switch ($cspMode) {
        case 'download':
            // File proxy: block all resources — only binary content served
            header("Content-Security-Policy: default-src 'none'");
            break;
        case 'login':
            // Auth page: unsafe-inline styles (inline CSS present), no connect-src
            header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'{$nonce}");
            break;
        case 'no-connect':
            // Pages with no direct AJAX (dashboard, calendar)
            header("Content-Security-Policy: default-src 'self'; style-src 'self'{$nonce}; script-src 'self'{$nonce}");
            break;
        case 'unsafe-style':
            // Pages using inline style attributes for dynamic values (views, index)
            header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'{$nonce}; connect-src 'self'");
            break;
        default:
            header("Content-Security-Policy: default-src 'self'; style-src 'self'{$nonce}; script-src 'self'{$nonce}; connect-src 'self'");
            break;
    }
}

// Decides whether the current session must be rejected. A session is stale when
// it has exceeded its absolute lifetime (hard logout, independent of activity)
// or when the bound User-Agent hash no longer matches the request — the latter
// foils opportunistic reuse of a stolen cookie from a different client.
// Centralises a check that was previously duplicated, and missing, across pages
// and API endpoints, so every entry point now enforces it identically.
function session_is_stale(): bool
{
    if (isset($_SESSION['created_at']) && (time() - (int) $_SESSION['created_at']) > SESSION_MAX_LIFETIME) {
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

// Enforce session freshness for JSON API endpoints. No-op when no user is logged
// in (the endpoint's own auth gate handles that). On a stale session: destroy it
// and emit a 401 JSON error, then exit.
function enforce_session_json(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    if (session_is_stale()) {
        session_destroy();
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => 'Session expired']));
    }
}

// Enforce session freshness for HTML page controllers. No-op when not logged in.
// On a stale session: destroy it and redirect to the login page, then exit.
function enforce_session_redirect(string $loginUrl = 'login.php'): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    if (session_is_stale()) {
        session_destroy();
        header('Location: ' . $loginUrl);
        exit;
    }
}
