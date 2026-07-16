<?php

declare(strict_types=1);

// includes/admin/backup.php — admin api.php module: config export/import ZIP + table snapshots (export, import,
// backup_tables).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// Export JSON configurations as a ZIP file
if ($action === 'export') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP ZIP extension is disabled. Enable extension=zip in php.ini.']);
        exit;
    }

    $zip = new ZipArchive();
    // Random suffix prevents temp file enumeration attacks
    $zipFile = sys_get_temp_dir() . '/sparrow_config_' . bin2hex(random_bytes(8)) . '.zip';
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $configDir = __DIR__ . '/../../config/';
        // database.json excluded — contains plaintext DB credentials
        $filesToBackup = ['security.json'];
        foreach ($filesToBackup as $f) {
            if (file_exists($configDir . $f)) {
                $zip->addFile($configDir . $f, $f);
            }
        }
        // Keys living in the spw_config store are exported from the DB. Keep in sync
        // with $dbBackedImport below and with includes/config_store.php.
        require_once __DIR__ . '/../config_store.php';
        $dbBackedExport = [
            'anonymization', 'print', 'user_records', 'board', 'calendar', 'dashboard',
            'views', 'automations', 'workflows', 'files', 'settings', 'rag',
            'schema', 'menu',
        ];
        foreach ($dbBackedExport as $dbKey) {
            $cfg = config_get($dbKey);
            if (is_array($cfg)) {
                $zip->addFromString(
                    $dbKey . '.json',
                    (string) json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="sparrow_backup.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        unlink($zipFile);
        exit;
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Cannot create zip file']);
        exit;
    }
}

// Import JSON configurations from a ZIP file safely
if ($action === 'import' && isset($_FILES['backup_file'])) {
    if ($isDemoMode) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Import is disabled in Demo Mode.']);
        exit;
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP ZIP extension is disabled. Enable extension=zip in php.ini.']);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['backup_file']['tmp_name']) === true) {
        $extractPath = __DIR__ . '/../../config/';
        // 'database' is deliberately absent: export has never emitted database.json, so no
        // archive legitimately contains it, and repointing the DB must not be a side effect
        // of restoring a config backup. Connection settings are changed in Admin → Database.
        $importAllowed = ['schema', 'dashboard', 'calendar', 'board', 'security', 'workflows', 'files', 'views', 'automations', 'menu', 'settings', 'anonymization', 'rag', 'print', 'user_records'];
        // Keys stored in spw_config — imported via config_save, not extracted to disk.
        $dbBackedImport = [
            'anonymization', 'print', 'user_records', 'board', 'calendar', 'dashboard',
            'views', 'automations', 'workflows', 'files', 'settings', 'rag',
            'schema', 'menu',
        ];
        $validFiles = [];

        // Validate each file inside the archive
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $basename = substr($filename, 0, -5); // strip .json suffix
            // Called out separately from the generic rejection below — a hand-built archive
            // carrying database.json deserves to know why, not "unknown config file".
            if ($basename === 'database' && str_ends_with($filename, '.json')) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'database.json cannot be imported — use Admin → Database to change '
                        . 'connection settings. Remove it from the archive and retry.',
                ]);
                exit;
            }
            // Reject any path separator (blocks subdirs and traversal), non-.json, or unknown config name
            if (
                str_contains($filename, '/') || str_contains($filename, '\\')
                || !str_ends_with($filename, '.json')
                || !in_array($basename, $importAllowed, true)
            ) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Invalid file detected. Only known config .json files are allowed.']);
                exit;
            }
            $validFiles[] = $filename;
        }

        if (empty($validFiles)) {
            $zip->close();
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Archive contains no recognised config files.']);
            exit;
        }

        // Phase 1 — validate every entry before touching any config, so a bad file at
        // the end of the archive cannot leave a half-applied import behind.
        // The per-file cap prevents zip-bomb decompression from exhausting memory; it
        // matches the store's own limit, since each file becomes one spw_config value.
        $maxFileBytes = CONFIG_FILE_MAX_BYTES;
        $decodedFiles = [];
        foreach ($validFiles as $file) {
            $jsonContent = $zip->getFromName($file);
            if ($jsonContent === false) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Could not read file from archive: ' . $file]);
                exit;
            }
            if (strlen($jsonContent) > $maxFileBytes) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'File exceeds maximum allowed size: ' . $file]);
                exit;
            }
            // json_validate() checks syntax without building the value tree —
            // no allocation for archives at the per-file cap.
            if (!json_validate($jsonContent)) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Invalid JSON content in archive: ' . $file]);
                exit;
            }
            // Every config is a JSON object/array — a bare scalar is valid JSON but
            // never a valid config, and must not be silently skipped.
            $decoded = json_decode($jsonContent, true);
            if (!is_array($decoded)) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Config must be a JSON object: ' . $file]);
                exit;
            }
            $decodedFiles[$file] = $decoded;
        }

        // Phase 2 — apply. Failures are collected rather than ignored; a partial
        // import must never be reported as success.
        require_once __DIR__ . '/../config_store.php';
        $userId   = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $imported = [];
        $failed   = [];
        foreach ($decodedFiles as $file => $decoded) {
            $importKey = substr($file, 0, -5);
            if (in_array($importKey, $dbBackedImport, true)) {
                // Whole-config restore — last-write-wins by design (expected version null).
                $result = config_save($importKey, $decoded, null, $userId);
                if ($result['status'] !== 'ok') {
                    $failed[$file] = $result['error'] ?? 'Save failed';
                    continue;
                }
                $imported[] = $file;
                continue;
            }
            if (!$zip->extractTo($extractPath, $file)) {
                $failed[$file] = 'Could not write file';
                continue;
            }
            $imported[] = $file;
        }

        $zip->close();
        header('Content-Type: application/json');
        if (!empty($failed)) {
            http_response_code(500);
            // The admin UI surfaces only "error", so name the failed configs there.
            echo json_encode([
                'error'    => 'Import incomplete — ' . count($failed) . ' of ' . count($decodedFiles)
                    . ' configs failed to apply: ' . implode(', ', array_keys($failed))
                    . '. Other configs were imported; re-run after fixing the cause.',
                'failed'   => $failed,
                'imported' => $imported,
            ]);
            exit;
        }
        echo json_encode(['status' => 'success', 'imported' => $imported]);
        exit;
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid zip file']);
    exit;
}

// Create a timestamped copy of selected tables (structure + data, no indexes/constraints)
if ($action === 'backup_tables') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $tables = $input['tables'] ?? [];
    if (empty($tables) || !is_array($tables)) {
        echo json_encode(['status' => 'error', 'error' => 'No tables provided.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $prefix = date('YmdHi');
        $results = [];
        foreach ($tables as $t) {
            $tableName  = $t['name']   ?? '';
            $schemaName = $t['schema'] ?? '';
            if (empty($tableName) || empty($schemaName)) {
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Missing table or schema name.'];
                continue;
            }
            $backupName  = $prefix . '_' . $tableName;
            $safeSchema  = pg_escape_identifier($conn, $schemaName);
            $safeSource  = pg_escape_identifier($conn, $tableName);
            $safeBackup  = pg_escape_identifier($conn, $backupName);
            $sql = "CREATE TABLE $safeSchema.$safeBackup AS SELECT * FROM $safeSchema.$safeSource";
            $res = @pg_query($conn, $sql);
            if ($res) {
                $rows = pg_affected_rows($res);
                $results[] = ['table' => $tableName, 'backup' => $backupName, 'status' => 'success', 'rows' => $rows];
            } else {
                error_log('[admin_api][backup_tables] ' . pg_last_error($conn));
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Database error. Check server logs.'];
            }
        }
        echo json_encode(['status' => 'success', 'results' => $results]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
