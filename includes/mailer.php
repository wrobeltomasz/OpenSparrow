<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function os_mail_delivery_config(): array
{
    require_once __DIR__ . '/config_store.php';
    $settings = config_get('settings') ?? [];
    $smtpEnabled = !empty($settings['smtp_enabled']);
    $smtpPassword = '';
    if ($smtpEnabled) {
        require_once __DIR__ . '/crypto.php';
        $smtpPassword = (string) (secret_decrypt((string) ($settings['smtp_password_enc'] ?? '')) ?? '');
    }
    return [
        'smtp_enabled' => $smtpEnabled,
        'host'          => (string) ($settings['smtp_host'] ?? ''),
        'port'          => (int) ($settings['smtp_port'] ?? 587),
        'encryption'    => (string) ($settings['smtp_encryption'] ?? 'tls'),
        'username'      => (string) ($settings['smtp_username'] ?? ''),
        'password'      => $smtpPassword,
        'from'          => AUTOMATION_EMAIL_FROM,
        'timeout'       => SMTP_TIMEOUT,
    ];
}

function os_send_mail_now(string $recipient, string $subject, string $body): bool
{
    $headerSafe = static fn(string $headerValue): string => str_replace(["\r", "\n"], ' ', $headerValue);
    $config = os_mail_delivery_config();
    $recipient = $headerSafe(trim($recipient));
    $subject = $headerSafe($subject);

    if ($config['from'] === '') {
        error_log('[mailer] delivery skipped: AUTOMATION_EMAIL_FROM is not configured');
        return false;
    }
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        error_log('[mailer] delivery skipped: invalid recipient address');
        return false;
    }

    if ($config['smtp_enabled']) {
        if ($config['host'] === '') {
            error_log('[mailer] delivery skipped: SMTP enabled but no host configured');
            return false;
        }
        require_once __DIR__ . '/smtp_client.php';
        $result = smtp_send($config, $recipient, $subject, $body);
        if (!$result['ok']) {
            error_log('[mailer] SMTP delivery failed: ' . (string) ($result['error'] ?? ''));
            return false;
        }
        return true;
    }

    $headers = 'From: ' . $headerSafe($config['from']) . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit";
    $sent = @mail(
        $recipient,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $body,
        $headers
    );
    if (!$sent) {
        error_log('[mailer] PHP mail() delivery failed');
    }
    return $sent;
}
