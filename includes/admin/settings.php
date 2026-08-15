<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

if ($action === 'list_icons') {
    $icons = [];

    $dirsToScan = [
        'assets/icons' => __DIR__ . '/../../public/assets/icons',
    ];
    foreach ($dirsToScan as $prefix => $dirPath) {
        if (is_dir($dirPath)) {
            $files = scandir($dirPath);
            foreach ($files as $iconFile) {
                if ($iconFile !== '.' && $iconFile !== '..') {
                    $ext = strtolower(pathinfo($iconFile, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'gif'])) {
                        $icons[] = $prefix . '/' . $iconFile;
                    }
                }
            }
        }
    }
    echo json_encode(['status' => 'success', 'icons' => array_values(array_unique($icons))]);
    exit;
}

if ($action === 'get_snapshot_setting') {
    $envVal = getenv('RECORD_SNAPSHOTS_ENABLED');
    $lockedByEnv = ($envVal !== false && $envVal !== '');
    $enabled = false;
    if ($lockedByEnv) {
        $enabled = ($envVal === 'true');
    } else {
        $s = admin_read_settings();
        $enabled = (bool) ($s['record_snapshots_enabled'] ?? false);
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = @db_connect();
        $tSnap = sys_table('record_snapshots');
        $countRes = $conn ? @pg_query($conn, "SELECT COUNT(*) FROM $tSnap") : false;
        $snapshotCount = ($countRes && ($cr = pg_fetch_row($countRes))) ? (int) $cr[0] : null;
        $tableExists = ($countRes !== false);
    } catch (Throwable $e) {
        $snapshotCount = null;
        $tableExists = false;
    }

    echo json_encode([
        'enabled'        => $enabled,
        'locked_by_env'  => $lockedByEnv,
        'table_exists'   => $tableExists,
        'snapshot_count' => $snapshotCount,
    ]);
    exit;
}

if ($action === 'set_snapshot_setting') {
    require_not_demo();
    $envVal = getenv('RECORD_SNAPSHOTS_ENABLED');
    if ($envVal !== false && $envVal !== '') {
        echo json_encode([
            'status' => 'error',
            'error'  => 'Controlled by RECORD_SNAPSHOTS_ENABLED environment variable — cannot override'
                . ' from admin panel.',
        ]);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = (bool) ($body['enabled'] ?? false);
    $settings = admin_read_settings();
    $settings['record_snapshots_enabled'] = $enabled;
    admin_save_settings($settings);
    echo json_encode(['status' => 'success', 'enabled' => $enabled]);
    exit;
}

if ($action === 'get_automation_email_setting') {
    $envVal = getenv('AUTOMATION_EMAIL_FROM');
    $lockedByEnv = ($envVal !== false && $envVal !== '');
    $settings = admin_read_settings();
    $from = $lockedByEnv ? $envVal : (string) ($settings['automation_email_from'] ?? '');

    echo json_encode([
        'from'                    => $from,
        'locked_by_env'           => $lockedByEnv,
        'smtp_enabled'            => (bool) ($settings['smtp_enabled'] ?? false),
        'smtp_host'               => (string) ($settings['smtp_host'] ?? ''),
        'smtp_port'               => (int) ($settings['smtp_port'] ?? 587),
        'smtp_encryption'         => (string) ($settings['smtp_encryption'] ?? 'tls'),
        'smtp_username'           => (string) ($settings['smtp_username'] ?? ''),
        'smtp_password_configured' => !empty($settings['smtp_password_enc']),
    ]);
    exit;
}

if ($action === 'set_automation_email_setting') {
    require_not_demo();
    $envVal = getenv('AUTOMATION_EMAIL_FROM');
    $lockedByEnv = ($envVal !== false && $envVal !== '');

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $from = trim((string) ($body['from'] ?? ''));
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'error' => 'Enter a valid "From" email address, or leave empty.']);
        exit;
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
        echo json_encode(['status' => 'error', 'error' => 'SMTP host is required when SMTP delivery is enabled.']);
        exit;
    }

    $settings = admin_read_settings();
    if (!$lockedByEnv) {
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
    echo json_encode(['status' => 'success']);
    exit;
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

    $cfg = [
        'host'       => trim((string) ($body['smtp_host'] ?? '')),
        'port'       => (int) ($body['smtp_port'] ?? 587),
        'encryption' => (string) ($body['smtp_encryption'] ?? 'tls'),
        'username'   => trim((string) ($body['smtp_username'] ?? '')),
        'password'   => $password,
        'timeout'    => 10,
    ];

    $result = smtp_test_connection($cfg);
    echo json_encode($result['ok']
        ? ['status' => 'success']
        : ['status' => 'error', 'error' => $result['error']]);
    exit;
}

if ($action === 'get_language_setting') {
    $settings = admin_read_settings();

    $defaultLanguage = is_string($settings['default_language'] ?? null) ? $settings['default_language'] : 'en';

    $langDir    = __DIR__ . '/../../languages/';
    $allLocales = [];
    foreach (glob($langDir . '*.json') ?: [] as $f) {
        $code = basename($f, '.json');
        $data = @json_decode((string)@file_get_contents($f), true) ?? [];
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
    exit;
}

if ($action === 'set_language_setting') {
    require_not_demo();

    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $defaultLang = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', (string)($body['default_language'] ?? ''))
        ? (string)$body['default_language']
        : 'en';

    $langDir      = __DIR__ . '/../../languages/';
    $installed    = array_map(
        static fn(string $f): string => basename($f, '.json'),
        glob($langDir . '*.json') ?: []
    );
    if (!in_array($defaultLang, $installed, true)) {
        echo json_encode(['status' => 'error', 'error' => 'Default language must be an installed language.']);
        exit;
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
    exit;
}

if ($action === 'get_chat_bubble_setting') {
    $settings = admin_read_settings();
    echo json_encode(['chat_bubble_enabled' => (bool) ($settings['chat_bubble_enabled'] ?? false)]);
    exit;
}

if ($action === 'set_chat_bubble_setting') {
    require_not_demo();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = !empty($body['chat_bubble_enabled']);

    $settings = admin_read_settings();
    $settings['chat_bubble_enabled'] = $enabled;

    admin_save_settings($settings);
    echo json_encode(['status' => 'success', 'chat_bubble_enabled' => $enabled]);
    exit;
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
    exit;
}

if ($action === 'set_app_name') {
    require_not_demo();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $appName = trim((string) ($body['app_name'] ?? ''));

    if ($appName === '' || mb_strlen($appName) > 60) {
        echo json_encode(['status' => 'error', 'error' => 'App name must be 1-60 characters.']);
        exit;
    }

    $settings = admin_read_settings();
    $settings['app_name'] = $appName;

    admin_save_settings($settings);
    echo json_encode(['status' => 'success', 'app_name' => $appName]);
    exit;
}

if ($action === 'set_logo_enabled') {
    require_not_demo();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = !empty($body['logo_enabled']);

    $settings = admin_read_settings();
    $settings['logo_enabled'] = $enabled;

    admin_save_settings($settings);
    echo json_encode(['status' => 'success', 'logo_enabled' => $enabled]);
    exit;
}

if ($action === 'upload_logo') {
    require_not_demo();

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'error' => 'No file received or upload error.']);
        exit;
    }

    $upload = $_FILES['file'];

    $maxBytes = 2 * 1024 * 1024;
    if ($upload['size'] > $maxBytes) {
        echo json_encode(['status' => 'error', 'error' => 'Logo must be 2 MB or smaller.']);
        exit;
    }

    $allowedMimes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mimeType = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($upload['tmp_name']) ?: 'application/octet-stream';
    }
    if (!isset($allowedMimes[$mimeType])) {
        echo json_encode(['status' => 'error', 'error' => 'Only PNG, JPEG or WEBP images are allowed.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../../public/assets/img/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);

        @file_put_contents(
            $uploadDir . '/.htaccess',
            "<FilesMatch \"\\.(php\\d?|phtml|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n"
        );
    }

    $ext         = $allowedMimes[$mimeType];
    $filename    = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        echo json_encode(['status' => 'error', 'error' => 'Failed to save the uploaded file.']);
        exit;
    }

    $settings     = admin_read_settings();

    $oldPath   = $settings['custom_logo_path'] ?? null;
    $uploadDirReal = realpath($uploadDir) ?: '';
    if (is_string($oldPath) && $oldPath !== '' && $uploadDirReal !== '') {
        $oldReal = realpath(__DIR__ . '/../../public/' . ltrim($oldPath, '/'));
        if ($oldReal !== false && str_starts_with($oldReal, $uploadDirReal)) {
            @unlink($oldReal);
        }
    }

    $settings['custom_logo_path'] = '/assets/img/uploads/' . $filename;

    $settings['logo_enabled'] = true;
    admin_save_settings($settings);

    echo json_encode(['status' => 'success', 'logo_path' => $settings['custom_logo_path'], 'logo_enabled' => true]);
    exit;
}

if ($action === 'remove_logo') {
    require_not_demo();

    $settings     = admin_read_settings();
    $oldPath      = $settings['custom_logo_path'] ?? null;

    if (is_string($oldPath) && $oldPath !== '') {
        $uploadDirReal = realpath(__DIR__ . '/../../public/assets/img/uploads') ?: '';
        $oldReal       = realpath(__DIR__ . '/../../public/' . ltrim($oldPath, '/'));
        if ($uploadDirReal !== '' && $oldReal !== false && str_starts_with($oldReal, $uploadDirReal)) {
            @unlink($oldReal);
        }
    }
    unset($settings['custom_logo_path']);

    $settings['logo_enabled'] = false;

    admin_save_settings($settings);

    echo json_encode(['status' => 'success', 'logo_enabled' => false]);
    exit;
}
