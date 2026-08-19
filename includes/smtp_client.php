<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('smtp_read_response')) {
    function smtp_read_response($socket): array
    {
        $code = null;
        $lines = [];
        while (!feof($socket)) {
            $line = fgets($socket, 1024);
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
    function smtp_command($socket, string $command, int $expectCode): array
    {
        fwrite($socket, $command . "\r\n");
        $response = smtp_read_response($socket);
        if ($response['code'] !== $expectCode) {
            return ['ok' => false, 'error' => 'Unexpected response to "' . $command . '": ' . $response['text']];
        }
        return ['ok' => true, 'response' => $response];
    }
}

if (!function_exists('smtp_connect_and_auth')) {
    function smtp_connect_and_auth(array $config): array
    {
        $host       = (string) ($config['host'] ?? '');
        $port       = (int) ($config['port'] ?? 587);
        $encryption = (string) ($config['encryption'] ?? 'tls');
        $username   = (string) ($config['username'] ?? '');
        $password   = (string) ($config['password'] ?? '');
        $timeout    = max(1, (int) ($config['timeout'] ?? SMTP_TIMEOUT));

        if ($host === '') {
            return ['ok' => false, 'sock' => null, 'error' => 'SMTP host is not configured.'];
        }

        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            return ['ok' => false, 'sock' => null, 'error' => "Connection failed: $errstr ($errno)"];
        }
        stream_set_timeout($socket, $timeout);

        $greeting = smtp_read_response($socket);
        if ($greeting['code'] !== 220) {
            fclose($socket);
            return ['ok' => false, 'sock' => null, 'error' => 'No SMTP greeting: ' . $greeting['text']];
        }

        $heloDomain = 'localhost';
        $ehlo = smtp_command($socket, 'EHLO ' . $heloDomain, 250);
        if (!$ehlo['ok']) {
            fclose($socket);
            return ['ok' => false, 'sock' => null, 'error' => $ehlo['error']];
        }

        if ($encryption === 'tls') {
            $starttls = smtp_command($socket, 'STARTTLS', 220);
            if (!$starttls['ok']) {
                fclose($socket);
                return ['ok' => false, 'sock' => null, 'error' => $starttls['error']];
            }
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                fclose($socket);
                return ['ok' => false, 'sock' => null, 'error' => 'TLS negotiation (STARTTLS) failed.'];
            }

            $ehlo = smtp_command($socket, 'EHLO ' . $heloDomain, 250);
            if (!$ehlo['ok']) {
                fclose($socket);
                return ['ok' => false, 'sock' => null, 'error' => $ehlo['error']];
            }
        }

        if ($username !== '') {
            $authentication = smtp_command($socket, 'AUTH LOGIN', 334);
            if (!$authentication['ok']) {
                fclose($socket);
                return ['ok' => false, 'sock' => null, 'error' => $authentication['error']];
            }
            $authenticationUser = smtp_command($socket, base64_encode($username), 334);
            if (!$authenticationUser['ok']) {
                fclose($socket);
                return ['ok' => false, 'sock' => null, 'error' => 'SMTP server rejected the username.'];
            }
            $authenticationPass = smtp_command($socket, base64_encode($password), 235);
            if (!$authenticationPass['ok']) {
                fclose($socket);
                return ['ok' => false, 'sock' => null, 'error' => 'SMTP server rejected the password.'];
            }
        }

        return ['ok' => true, 'sock' => $socket, 'error' => null];
    }
}

if (!function_exists('smtp_test_connection')) {
    function smtp_test_connection(array $config): array
    {
        $conn = smtp_connect_and_auth($config);
        if (!$conn['ok']) {
            return ['ok' => false, 'error' => $conn['error']];
        }
        smtp_command($conn['sock'], 'QUIT', 221);
        fclose($conn['sock']);
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('smtp_send')) {
    function smtp_send(array $config, string $recipient, string $subject, string $body): array
    {
        $from = (string) ($config['from'] ?? '');
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid or missing From address.'];
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient address.'];
        }

        $conn = smtp_connect_and_auth($config);
        if (!$conn['ok']) {
            return ['ok' => false, 'error' => $conn['error']];
        }
        $socket = $conn['sock'];

        $mailFrom = smtp_command($socket, 'MAIL FROM:<' . $from . '>', 250);
        if (!$mailFrom['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => $mailFrom['error']];
        }
        $rcptTo = smtp_command($socket, 'RCPT TO:<' . $recipient . '>', 250);
        if (!$rcptTo['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => $rcptTo['error']];
        }
        $data = smtp_command($socket, 'DATA', 354);
        if (!$data['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => $data['error']];
        }

        $bodyNormalized = str_replace("\r\n", "\n", $body);
        $bodyNormalized = str_replace("\n", "\r\n", $bodyNormalized);
        $bodyStuffed = preg_replace('/^\./m', '..', $bodyNormalized) ?? $bodyNormalized;

        $headerSafe = static fn(string $headerValue): string => str_replace(["\r", "\n"], ' ', $headerValue);
        $headers = 'From: ' . $headerSafe($from) . "\r\n"
            . 'To: ' . $headerSafe($recipient) . "\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($headerSafe($subject)) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n";

        fwrite($socket, $headers . $bodyStuffed . "\r\n.\r\n");
        $sent = smtp_read_response($socket);
        if ($sent['code'] !== 250) {
            fclose($socket);
            return ['ok' => false, 'error' => 'Server rejected message: ' . $sent['text']];
        }

        smtp_command($socket, 'QUIT', 221);
        fclose($socket);
        return ['ok' => true, 'error' => null];
    }
}
