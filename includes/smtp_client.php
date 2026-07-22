<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// smtp_client.php — Minimal dependency-free SMTP client (EHLO / STARTTLS / AUTH LOGIN / DATA)
// via native PHP stream sockets. No external library (project convention: no composer/npm
// runtime dependencies). Used by cron/cron_notifications.php to deliver spw_automation_emails
// when SMTP is configured in the "settings" spw_config key; falls back to mail() otherwise.

declare(strict_types=1);

// Reads one SMTP response, following multi-line replies ("250-...\n250 ...").
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

// Sends one SMTP command and validates the response code.
if (!function_exists('smtp_command')) {
    function smtp_command($sock, string $command, int $expectCode): array
    {
        fwrite($sock, $command . "\r\n");
        $resp = smtp_read_response($sock);
        if ($resp['code'] !== $expectCode) {
            return ['ok' => false, 'error' => 'Unexpected response to "' . $command . '": ' . $resp['text']];
        }
        return ['ok' => true, 'response' => $resp];
    }

}

// Opens the connection, performs EHLO, optional STARTTLS and optional AUTH LOGIN.
// Returns ['ok' => bool, 'sock' => resource|null, 'error' => ?string]. The caller
// is responsible for fclose()-ing the socket once done.
if (!function_exists('smtp_connect_and_auth')) {
    function smtp_connect_and_auth(array $cfg): array
    {
        $host       = (string) ($cfg['host'] ?? '');
        $port       = (int) ($cfg['port'] ?? 587);
        $encryption = (string) ($cfg['encryption'] ?? 'tls'); // none|ssl|tls
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
            // Re-issue EHLO after STARTTLS, per RFC 3207.
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

// Connects, authenticates (if credentials given) and immediately QUITs — used by
// the admin "Test connection" button to validate settings without sending mail.
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

// Sends one plain-text email via SMTP. $cfg needs host/port/encryption/username/password/from.
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

        // Normalize to CRLF, then dot-stuff lines starting with "." per RFC 5321 §4.5.2.
        $bodyNormalized = str_replace("\r\n", "\n", $body);
        $bodyNormalized = str_replace("\n", "\r\n", $bodyNormalized);
        $bodyStuffed = preg_replace('/^\./m', '..', $bodyNormalized) ?? $bodyNormalized;

        $headerSafe = static fn(string $s): string => str_replace(["\r", "\n"], ' ', $s);
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
