<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

if ($action === 'list_icons') {
    $icons = [];

    $directoriesToScan = [
        'assets/icons' => __DIR__ . '/../../public/assets/icons',
    ];
    foreach ($directoriesToScan as $prefix => $directoryPath) {
        if (is_dir($directoryPath)) {
            $files = scandir($directoryPath);
            foreach ($files as $iconFile) {
                if ($iconFile !== '.' && $iconFile !== '..') {
                    $extension = strtolower(pathinfo($iconFile, PATHINFO_EXTENSION));
                    if (in_array($extension, ['png', 'jpg', 'jpeg', 'svg', 'gif'])) {
                        $icons[] = $prefix . '/' . $iconFile;
                    }
                }
            }
        }
    }
    admin_ok(['icons' => array_values(array_unique($icons))]);
}

if ($action === 'get_snapshot_setting') {
    $environmentValue = getenv('RECORD_SNAPSHOTS_ENABLED');
    $lockedByEnvironment = ($environmentValue !== false && $environmentValue !== '');
    $enabled = false;
    if ($lockedByEnvironment) {
        $enabled = ($environmentValue === 'true');
    } else {
        $settings = admin_read_settings();
        $enabled = (bool) ($settings['record_snapshots_enabled'] ?? false);
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = @db_connect();
        $recordSnapshotsTable = sys_table('record_snapshots');
        $countResult = $conn ? @pg_query($conn, "SELECT COUNT(*) FROM $recordSnapshotsTable") : false;
        $snapshotCount = ($countResult && ($countRow = pg_fetch_row($countResult))) ? (int) $countRow[0] : null;
        $tableExists = ($countResult !== false);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        $snapshotCount = null;
        $tableExists = false;
    }

    echo json_encode([
        'enabled'        => $enabled,
        'locked_by_env'  => $lockedByEnvironment,
        'table_exists'   => $tableExists,
        'snapshot_count' => $snapshotCount,
    ]);
    throw ResponseException::sent();
}

if ($action === 'set_snapshot_setting') {
    require_not_demo();
    $environmentValue = getenv('RECORD_SNAPSHOTS_ENABLED');
    if ($environmentValue !== false && $environmentValue !== '') {
        echo json_encode([
            'status' => 'error',
            'error'  => 'Controlled by RECORD_SNAPSHOTS_ENABLED environment variable — cannot override'
                . ' from admin panel.',
        ]);
        throw ResponseException::sent();
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = (bool) ($body['enabled'] ?? false);
    $settings = admin_read_settings();
    $settings['record_snapshots_enabled'] = $enabled;
    admin_save_settings($settings);
    admin_ok(['enabled' => $enabled]);
}

if ($action === 'get_automation_email_setting') {
    $environmentValue = getenv('AUTOMATION_EMAIL_FROM');
    $lockedByEnvironment = ($environmentValue !== false && $environmentValue !== '');
    $settings = admin_read_settings();
    $from = $lockedByEnvironment ? $environmentValue : (string) ($settings['automation_email_from'] ?? '');

    echo json_encode([
        'from'                    => $from,
        'locked_by_env'           => $lockedByEnvironment,
        'smtp_enabled'            => (bool) ($settings['smtp_enabled'] ?? false),
        'smtp_host'               => (string) ($settings['smtp_host'] ?? ''),
        'smtp_port'               => (int) ($settings['smtp_port'] ?? 587),
        'smtp_encryption'         => (string) ($settings['smtp_encryption'] ?? 'tls'),
        'smtp_username'           => (string) ($settings['smtp_username'] ?? ''),
        'smtp_password_configured' => !empty($settings['smtp_password_enc']),
    ]);
    throw ResponseException::sent();
}

if ($action === 'set_automation_email_setting') {
    require_not_demo();
    $environmentValue = getenv('AUTOMATION_EMAIL_FROM');
    $lockedByEnvironment = ($environmentValue !== false && $environmentValue !== '');

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $from = trim((string) ($body['from'] ?? ''));
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        admin_err('Enter a valid "From" email address, or leave empty.');
    }

    $smtpEnabled    = !empty($body['smtp_enabled']);
    $smtpHost       = trim((string) ($body['smtp_host'] ?? ''));
    $smtpPort       = max(1, min(65535, (int) ($body['smtp_port'] ?? 587)));
    $smtpEncryption = (string) ($body['smtp_encryption'] ?? 'tls');
    if (!in_array($smtpEncryption, ['none', 'ssl', 'tls'], true)) {
        $smtpEncryption = 'tls';
    }
    $smtpUsername   = trim((string) ($body['smtp_username'] ?? ''));
    $smtpPassword   = isset($body['smtp_password']) ? (string) $body['smtp_password'] : '';
    $clearPassword  = !empty($body['smtp_password_clear']);

    if ($smtpEnabled && $smtpHost === '') {
        admin_err('SMTP host is required when SMTP delivery is enabled.');
    }

    $settings = admin_read_settings();
    if (!$lockedByEnvironment) {
        $settings['automation_email_from'] = $from;
    }
    $settings['smtp_enabled']    = $smtpEnabled;
    $settings['smtp_host']       = $smtpHost;
    $settings['smtp_port']       = $smtpPort;
    $settings['smtp_encryption'] = $smtpEncryption;
    $settings['smtp_username']   = $smtpUsername;

    require_once __DIR__ . '/../crypto.php';
    if ($clearPassword) {
        unset($settings['smtp_password_enc']);
    } elseif ($smtpPassword !== '') {
        $settings['smtp_password_enc'] = secret_encrypt($smtpPassword);
    }

    admin_save_settings($settings);
    admin_ok();
}

if ($action === 'test_smtp_connection') {
    require_not_demo();
    require_once __DIR__ . '/../smtp_client.php';
    require_once __DIR__ . '/../crypto.php';

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $settings = admin_read_settings();

    $password = isset($body['smtp_password']) && $body['smtp_password'] !== ''
        ? (string) $body['smtp_password']
        : (string) (secret_decrypt((string) ($settings['smtp_password_enc'] ?? '')) ?? '');

    $config = [
        'host'       => trim((string) ($body['smtp_host'] ?? '')),
        'port'       => (int) ($body['smtp_port'] ?? 587),
        'encryption' => (string) ($body['smtp_encryption'] ?? 'tls'),
        'username'   => trim((string) ($body['smtp_username'] ?? '')),
        'password'   => $password,
        'timeout'    => 10,
    ];

    $result = smtp_test_connection($config);
    echo json_encode($result['ok']
        ? ['status' => 'success']
        : ['status' => 'error', 'error' => $result['error']]);
    throw ResponseException::sent();
}

if ($action === 'get_language_setting') {
    $settings = admin_read_settings();

    $defaultLanguage = is_string($settings['default_language'] ?? null) ? $settings['default_language'] : 'en';

    $langDirectory    = __DIR__ . '/../../languages/';
    $allLocales = [];
    foreach (glob($langDirectory . '*.json') ?: [] as $languageFile) {
        $code = basename($languageFile, '.json');
        $data = json_decode((string)@file_get_contents($languageFile), true) ?? [];
        $allLocales[] = [
            'code' => $code,
            'name' => is_string($data['_meta']['name'] ?? null) ? $data['_meta']['name'] : $code,
        ];
    }

    echo json_encode([
        'default_language'    => $defaultLanguage,
        'available_languages' => array_column($allLocales, 'code'),
        'all_locales'         => $allLocales,
    ]);
    throw ResponseException::sent();
}

if ($action === 'set_language_setting') {
    require_not_demo();

    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $defaultLang = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', (string)($body['default_language'] ?? ''))
        ? (string)$body['default_language']
        : 'en';

    $langDirectory      = __DIR__ . '/../../languages/';
    $installed    = array_map(
        static fn(string $languageFile): string => basename($languageFile, '.json'),
        glob($langDirectory . '*.json') ?: []
    );
    if (!in_array($defaultLang, $installed, true)) {
        admin_err('Default language must be an installed language.');
    }

    $settings = admin_read_settings();
    if (($settings['default_language'] ?? null) !== $defaultLang) {
        $settings['locale_version'] = bin2hex(random_bytes(8));
    }
    if (!isset($settings['locale_version'])) {
        $settings['locale_version'] = bin2hex(random_bytes(8));
    }
    $settings['default_language'] = $defaultLang;
    unset($settings['available_languages']);

    admin_save_settings($settings);

    echo json_encode([
        'status'           => 'success',
        'default_language' => $defaultLang,
    ]);
    throw ResponseException::sent();
}

if ($action === 'get_chat_bubble_setting') {
    $settings = admin_read_settings();
    throw ResponseException::encoded(['chat_bubble_enabled' => (bool) ($settings['chat_bubble_enabled'] ?? false)]);
}

if ($action === 'set_chat_bubble_setting') {
    require_not_demo();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = !empty($body['chat_bubble_enabled']);

    $settings = admin_read_settings();
    $settings['chat_bubble_enabled'] = $enabled;

    admin_save_settings($settings);
    admin_ok(['chat_bubble_enabled' => $enabled]);
}

if ($action === 'get_logo_setting') {
    $settings = admin_read_settings();
    $logoPath = $settings['custom_logo_path'] ?? null;
    $appName  = $settings['app_name'] ?? null;
    echo json_encode([
        'logo_path'    => is_string($logoPath) ? $logoPath : null,
        'logo_enabled' => (bool) ($settings['logo_enabled'] ?? false),
        'app_name'     => is_string($appName) && $appName !== '' ? $appName : 'OpenSparrow',
    ]);
    throw ResponseException::sent();
}

if ($action === 'set_app_name') {
    require_not_demo();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $appName = trim((string) ($body['app_name'] ?? ''));

    if ($appName === '' || mb_strlen($appName) > 60) {
        admin_err('App name must be 1-60 characters.');
    }

    $settings = admin_read_settings();
    $settings['app_name'] = $appName;

    admin_save_settings($settings);
    admin_ok(['app_name' => $appName]);
}

if ($action === 'set_logo_enabled') {
    require_not_demo();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = !empty($body['logo_enabled']);

    $settings = admin_read_settings();
    $settings['logo_enabled'] = $enabled;

    admin_save_settings($settings);
    admin_ok(['logo_enabled' => $enabled]);
}

if ($action === 'upload_logo') {
    require_not_demo();

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        admin_err('No file received or upload error.');
    }

    $upload = $_FILES['file'];

    $maxBytes = 2 * 1024 * 1024;
    if ($upload['size'] > $maxBytes) {
        admin_err('Logo must be 2 MB or smaller.');
    }

    $allowedMimes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mimeType = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($upload['tmp_name']) ?: 'application/octet-stream';
    }
    if (!isset($allowedMimes[$mimeType])) {
        admin_err('Only PNG, JPEG or WEBP images are allowed.');
    }

    $uploadDirectory = __DIR__ . '/../../public/assets/img/uploads';
    os_ensure_directory($uploadDirectory, 0755);
    os_write_guard_file(
        $uploadDirectory . '/.htaccess',
        "<FilesMatch \"\\.(php\\d?|phtml|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n"
    );

    $extension         = $allowedMimes[$mimeType];
    $filename    = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $destination = $uploadDirectory . '/' . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        admin_err('Failed to save the uploaded file.');
    }

    $settings     = admin_read_settings();

    $oldPath   = $settings['custom_logo_path'] ?? null;
    $uploadDirectoryReal = realpath($uploadDirectory) ?: '';
    if (is_string($oldPath) && $oldPath !== '' && $uploadDirectoryReal !== '') {
        $oldReal = realpath(__DIR__ . '/../../public/' . ltrim($oldPath, '/'));
        if ($oldReal !== false && str_starts_with($oldReal, $uploadDirectoryReal)) {
            @unlink($oldReal);
        }
    }

    $settings['custom_logo_path'] = '/assets/img/uploads/' . $filename;

    $settings['logo_enabled'] = true;
    admin_save_settings($settings);

    admin_ok(['logo_path' => $settings['custom_logo_path'], 'logo_enabled' => true]);
}

if ($action === 'remove_logo') {
    require_not_demo();

    $settings     = admin_read_settings();
    $oldPath      = $settings['custom_logo_path'] ?? null;

    if (is_string($oldPath) && $oldPath !== '') {
        $uploadDirectoryReal = realpath(__DIR__ . '/../../public/assets/img/uploads') ?: '';
        $oldReal       = realpath(__DIR__ . '/../../public/' . ltrim($oldPath, '/'));
        if ($uploadDirectoryReal !== '' && $oldReal !== false && str_starts_with($oldReal, $uploadDirectoryReal)) {
            @unlink($oldReal);
        }
    }
    unset($settings['custom_logo_path']);

    $settings['logo_enabled'] = false;

    admin_save_settings($settings);

    admin_ok(['logo_enabled' => false]);
}
