<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

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
