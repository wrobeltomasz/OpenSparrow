<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

if (!function_exists('smtp_read_response')) {
    function smtp_read_response($sock): array
    {
        $code = null;
        $lines = [];
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) {
                break;
            }
            $lines[] = rtrim($line, "\r\n");
            $code = (int) substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return ['code' => $code, 'text' => implode("\n", $lines)];
    }
}

if (!function_exists('smtp_command')) {
    function smtp_command($sock, string $command, int $expectCode): array
    {
        fwrite($sock, $command . "\r\n");
        $response = smtp_read_response($sock);
        if ($response['code'] !== $expectCode) {
            return ['ok' => false, 'error' => 'Unexpected response to "' . $command . '": ' . $response['text']];
        }
        return ['ok' => true, 'response' => $response];
    }
}

if (!function_exists('smtp_connect_and_auth')) {
    function smtp_connect_and_auth(array $cfg): array
    {
        $host       = (string) ($cfg['host'] ?? '');
        $port       = (int) ($cfg['port'] ?? 587);
        $encryption = (string) ($cfg['encryption'] ?? 'tls');
        $username   = (string) ($cfg['username'] ?? '');
        $password   = (string) ($cfg['password'] ?? '');
        $timeout    = (int) ($cfg['timeout'] ?? 10);

        if ($host === '') {
            return ['ok' => false, 'sock' => null, 'error' => 'SMTP host is not configured.'];
        }

        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $sock = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($sock === false) {
            return ['ok' => false, 'sock' => null, 'error' => "Connection failed: $errstr ($errno)"];
        }
        stream_set_timeout($sock, $timeout);

        $greeting = smtp_read_response($sock);
        if ($greeting['code'] !== 220) {
            fclose($sock);
            return ['ok' => false, 'sock' => null, 'error' => 'No SMTP greeting: ' . $greeting['text']];
        }

        $heloDomain = 'localhost';
        $ehlo = smtp_command($sock, 'EHLO ' . $heloDomain, 250);
        if (!$ehlo['ok']) {
            fclose($sock);
            return ['ok' => false, 'sock' => null, 'error' => $ehlo['error']];
        }

        if ($encryption === 'tls') {
            $starttls = smtp_command($sock, 'STARTTLS', 220);
            if (!$starttls['ok']) {
                fclose($sock);
                return ['ok' => false, 'sock' => null, 'error' => $starttls['error']];
            }
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (!@stream_socket_enable_crypto($sock, true, $cryptoMethod)) {
                fclose($sock);
                return ['ok' => false, 'sock' => null, 'error' => 'TLS negotiation (STARTTLS) failed.'];
            }

            $ehlo = smtp_command($sock, 'EHLO ' . $heloDomain, 250);
            if (!$ehlo['ok']) {
                fclose($sock);
                return ['ok' => false, 'sock' => null, 'error' => $ehlo['error']];
            }
        }

        if ($username !== '') {
            $auth = smtp_command($sock, 'AUTH LOGIN', 334);
            if (!$auth['ok']) {
                fclose($sock);
                return ['ok' => false, 'sock' => null, 'error' => $auth['error']];
            }
            $authUser = smtp_command($sock, base64_encode($username), 334);
            if (!$authUser['ok']) {
                fclose($sock);
                return ['ok' => false, 'sock' => null, 'error' => 'SMTP server rejected the username.'];
            }
            $authPass = smtp_command($sock, base64_encode($password), 235);
            if (!$authPass['ok']) {
                fclose($sock);
                return ['ok' => false, 'sock' => null, 'error' => 'SMTP server rejected the password.'];
            }
        }

        return ['ok' => true, 'sock' => $sock, 'error' => null];
    }
}

if (!function_exists('smtp_test_connection')) {
    function smtp_test_connection(array $cfg): array
    {
        $conn = smtp_connect_and_auth($cfg);
        if (!$conn['ok']) {
            return ['ok' => false, 'error' => $conn['error']];
        }
        smtp_command($conn['sock'], 'QUIT', 221);
        fclose($conn['sock']);
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('smtp_send')) {
    function smtp_send(array $cfg, string $to, string $subject, string $body): array
    {
        $from = (string) ($cfg['from'] ?? '');
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid or missing From address.'];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient address.'];
        }

        $conn = smtp_connect_and_auth($cfg);
        if (!$conn['ok']) {
            return ['ok' => false, 'error' => $conn['error']];
        }
        $sock = $conn['sock'];

        $mailFrom = smtp_command($sock, 'MAIL FROM:<' . $from . '>', 250);
        if (!$mailFrom['ok']) {
            fclose($sock);
            return ['ok' => false, 'error' => $mailFrom['error']];
        }
        $rcptTo = smtp_command($sock, 'RCPT TO:<' . $to . '>', 250);
        if (!$rcptTo['ok']) {
            fclose($sock);
            return ['ok' => false, 'error' => $rcptTo['error']];
        }
        $data = smtp_command($sock, 'DATA', 354);
        if (!$data['ok']) {
            fclose($sock);
            return ['ok' => false, 'error' => $data['error']];
        }

        $bodyNormalized = str_replace("\r\n", "\n", $body);
        $bodyNormalized = str_replace("\n", "\r\n", $bodyNormalized);
        $bodyStuffed = preg_replace('/^\./m', '..', $bodyNormalized) ?? $bodyNormalized;

        $headerSafe = static fn(string $headerValue): string => str_replace(["\r", "\n"], ' ', $headerValue);
        $headers = 'From: ' . $headerSafe($from) . "\r\n"
            . 'To: ' . $headerSafe($to) . "\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($headerSafe($subject)) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n";

        fwrite($sock, $headers . $bodyStuffed . "\r\n.\r\n");
        $sent = smtp_read_response($sock);
        if ($sent['code'] !== 250) {
            fclose($sock);
            return ['ok' => false, 'error' => 'Server rejected message: ' . $sent['text']];
        }

        smtp_command($sock, 'QUIT', 221);
        fclose($sock);
        return ['ok' => true, 'error' => null];
    }
}
