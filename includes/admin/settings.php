<?php

declare(strict_types=1);

// includes/admin/settings.php — admin api.php module: app settings + logo/icons (list_icons,
// get/set_snapshot_setting, get/set_language_setting,
// get/set_chat_bubble_setting, get_logo_setting, set_logo_enabled, upload_logo, remove_logo).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// Scan directories for available icons
if ($action === 'list_icons') {
    $icons = [];
    $dirsToScan = [
        'assets/icons' => __DIR__ . '/../../public/assets/icons',
        'assets/img' => __DIR__ . '/../../public/assets/img'
    ];
    foreach ($dirsToScan as $prefix => $dirPath) {
        if (is_dir($dirPath)) {
            $files = scandir($dirPath);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'gif'])) {
                        $icons[] = $prefix . '/' . $file;
                    }
                }
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'icons' => array_values(array_unique($icons))]);
    exit;
}

// GET: return current record-snapshot setting and whether it is locked by env var
if ($action === 'get_snapshot_setting') {
    header('Content-Type: application/json');
    $envVal = getenv('RECORD_SNAPSHOTS_ENABLED');
    $lockedByEnv = ($envVal !== false && $envVal !== '');
    $enabled = false;
    if ($lockedByEnv) {
        $enabled = ($envVal === 'true');
    } else {
        $s = admin_read_settings(__DIR__ . '/../../config/settings.json');
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

// POST: toggle record-snapshot setting in config/settings.json
if ($action === 'set_snapshot_setting') {
    header('Content-Type: application/json');
    require_not_demo();
    $envVal = getenv('RECORD_SNAPSHOTS_ENABLED');
    if ($envVal !== false && $envVal !== '') {
        echo json_encode(['status' => 'error', 'error' => 'Controlled by RECORD_SNAPSHOTS_ENABLED environment variable — cannot override from admin panel.']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = (bool) ($body['enabled'] ?? false);
    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings = admin_read_settings($settingsFile);
    $settings['record_snapshots_enabled'] = $enabled;
    $written = @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write config/settings.json. Check directory permissions.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'enabled' => $enabled]);
    exit;
}

// GET: return language settings and all available locales from languages/*.json
if ($action === 'get_language_setting') {
    header('Content-Type: application/json');
    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings = admin_read_settings($settingsFile);

    $defaultLanguage    = is_string($settings['default_language'] ?? null) ? $settings['default_language'] : 'en';
    $availableLanguages = is_array($settings['available_languages'] ?? null) ? $settings['available_languages'] : null;

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

    if ($availableLanguages === null) {
        $availableLanguages = array_column($allLocales, 'code');
    }

    echo json_encode([
        'default_language'    => $defaultLanguage,
        'available_languages' => $availableLanguages,
        'all_locales'         => $allLocales,
    ]);
    exit;
}

// POST: save language settings to config/settings.json
if ($action === 'set_language_setting') {
    header('Content-Type: application/json');
    require_not_demo();

    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $defaultLang = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', (string)($body['default_language'] ?? ''))
        ? (string)$body['default_language']
        : 'en';

    $available = array_values(array_filter(
        array_map('strval', (array)($body['available_languages'] ?? [])),
        static fn(string $l): bool => (bool)preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $l)
    ));

    if (empty($available)) {
        echo json_encode(['status' => 'error', 'error' => 'At least one language must be available.']);
        exit;
    }
    if (!in_array($defaultLang, $available, true)) {
        echo json_encode(['status' => 'error', 'error' => 'Default language must be in the available languages list.']);
        exit;
    }

    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings = admin_read_settings($settingsFile);
    if (($settings['default_language'] ?? null) !== $defaultLang) {
        $settings['locale_version'] = bin2hex(random_bytes(8));
    }
    if (!isset($settings['locale_version'])) {
        $settings['locale_version'] = bin2hex(random_bytes(8));
    }
    $settings['default_language']    = $defaultLang;
    $settings['available_languages'] = $available;

    $written = @file_put_contents(
        $settingsFile,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write config/settings.json. Check directory permissions.']);
        exit;
    }

    echo json_encode([
        'status'             => 'success',
        'default_language'   => $defaultLang,
        'available_languages' => $available,
    ]);
    exit;
}

// GET: return AI chat bubble setting
if ($action === 'get_chat_bubble_setting') {
    header('Content-Type: application/json');
    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings = admin_read_settings($settingsFile);
    echo json_encode(['chat_bubble_enabled' => (bool) ($settings['chat_bubble_enabled'] ?? false)]);
    exit;
}

// POST: save AI chat bubble setting
if ($action === 'set_chat_bubble_setting') {
    header('Content-Type: application/json');
    require_not_demo();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = !empty($body['chat_bubble_enabled']);

    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings = admin_read_settings($settingsFile);
    $settings['chat_bubble_enabled'] = $enabled;

    $written = @file_put_contents(
        $settingsFile,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write config/settings.json.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'chat_bubble_enabled' => $enabled]);
    exit;
}

// GET: return the current custom logo path and whether the header logo is shown at all.
// logo_enabled defaults to false — a fresh install shows no header logo, matching
// the layout before this feature existed.
if ($action === 'get_logo_setting') {
    header('Content-Type: application/json');
    $settings = admin_read_settings(__DIR__ . '/../../config/settings.json');
    $logoPath = $settings['custom_logo_path'] ?? null;
    echo json_encode([
        'logo_path'    => is_string($logoPath) ? $logoPath : null,
        'logo_enabled' => (bool) ($settings['logo_enabled'] ?? false),
    ]);
    exit;
}

// POST: toggle whether the header logo is shown at all (independent of the uploaded file)
if ($action === 'set_logo_enabled') {
    header('Content-Type: application/json');
    require_not_demo();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = !empty($body['logo_enabled']);

    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings = admin_read_settings($settingsFile);
    $settings['logo_enabled'] = $enabled;

    $written = @file_put_contents(
        $settingsFile,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write config/settings.json.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'logo_enabled' => $enabled]);
    exit;
}

// POST: upload a replacement logo shown on the frontend footer
if ($action === 'upload_logo') {
    header('Content-Type: application/json');
    require_not_demo();

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'error' => 'No file received or upload error.']);
        exit;
    }

    $file = $_FILES['file'];
    // A logo has no reason to be large; keeps the upload folder and page weight small.
    $maxBytes = 2 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        echo json_encode(['status' => 'error', 'error' => 'Logo must be 2 MB or smaller.']);
        exit;
    }

    // Content-sniff the actual bytes rather than trusting the client-supplied
    // extension/MIME — SVG is deliberately excluded to avoid inline-script XSS.
    $allowedMimes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mimeType = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    }
    if (!isset($allowedMimes[$mimeType])) {
        echo json_encode(['status' => 'error', 'error' => 'Only PNG, JPEG or WEBP images are allowed.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../../public/assets/img/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        // Defense in depth on top of the MIME whitelist above: this folder must stay
        // web-readable (the frontend <img> links directly into it), so — unlike
        // storage/files/.htaccess — script execution is blocked instead of all access.
        @file_put_contents(
            $uploadDir . '/.htaccess',
            "<FilesMatch \"\\.(php\\d?|phtml|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n"
        );
    }

    // Server-chosen random filename — never derived from the client's original name.
    $ext         = $allowedMimes[$mimeType];
    $filename    = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode(['status' => 'error', 'error' => 'Failed to save the uploaded file.']);
        exit;
    }

    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings     = admin_read_settings($settingsFile);

    // Remove the previous custom logo file so uploads don't accumulate on disk.
    $oldPath   = $settings['custom_logo_path'] ?? null;
    $uploadDirReal = realpath($uploadDir) ?: '';
    if (is_string($oldPath) && $oldPath !== '' && $uploadDirReal !== '') {
        $oldReal = realpath(__DIR__ . '/../../public/' . ltrim($oldPath, '/'));
        if ($oldReal !== false && str_starts_with($oldReal, $uploadDirReal)) {
            @unlink($oldReal);
        }
    }

    $settings['custom_logo_path'] = '/assets/img/uploads/' . $filename;
    // Uploading a logo implies wanting it shown; the enable toggle can still turn it off later.
    $settings['logo_enabled'] = true;
    $written = @file_put_contents(
        $settingsFile,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write config/settings.json.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'logo_path' => $settings['custom_logo_path'], 'logo_enabled' => true]);
    exit;
}

// POST: remove the custom logo and revert to the default OpenSparrow logo
if ($action === 'remove_logo') {
    header('Content-Type: application/json');
    require_not_demo();

    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings     = admin_read_settings($settingsFile);
    $oldPath      = $settings['custom_logo_path'] ?? null;

    if (is_string($oldPath) && $oldPath !== '') {
        $uploadDirReal = realpath(__DIR__ . '/../../public/assets/img/uploads') ?: '';
        $oldReal       = realpath(__DIR__ . '/../../public/' . ltrim($oldPath, '/'));
        if ($uploadDirReal !== '' && $oldReal !== false && str_starts_with($oldReal, $uploadDirReal)) {
            @unlink($oldReal);
        }
    }
    unset($settings['custom_logo_path']);
    // No custom image left to show — revert fully to the no-logo header, matching
    // the layout before this feature existed, rather than silently falling back
    // to the default OpenSparrow logo.
    $settings['logo_enabled'] = false;

    $written = @file_put_contents(
        $settingsFile,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write config/settings.json.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'logo_enabled' => false]);
    exit;
}
