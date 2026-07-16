<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// admin/api_migrations.php — Schema migrations admin API
// Auth/CSRF gate: os_api_bootstrap (401 guest / 403 non-admin, X-CSRF-Token on mutations)
// actions: scan (detect drift between schema.json and the live DB) and apply (run the generated migration SQL)
// Reversible migrations; parameterized/quoted identifiers

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/config_store.php';

os_api_bootstrap(['connect' => false, 'role' => 'admin']);

$action = $_GET['action'] ?? '';

if ($action === 'apply') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'error' => 'Method Not Allowed.']);
        exit;
    }
    if (DEMO_MODE) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'Blocked in demo mode.']);
        exit;
    }
}

$root         = (string) realpath(__DIR__ . '/../..');
$manifestPath = $root . '/config/migrations.json';

function rm_load_manifest(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function rm_db_and_applied(): array
{
    require_once __DIR__ . '/../../includes/db.php';
    $conn    = db_connect();
    $tRelMig = sys_table('release_migrations');
    $sql     = "SELECT version, applied_at, applied_by, actions FROM $tRelMig ORDER BY applied_at ASC";
    $res     = @pg_query($conn, $sql);
    $out     = [];
    if ($res) {
        while ($r = pg_fetch_assoc($res)) {
            $out[$r['version']] = $r;
        }
    }
    return [$conn, $out];
}

// Manifest "file" fields name a spw_config key, historically spelled with the
// legacy config/*.json file name ("schema.json" → key "schema"). Normalize both
// spellings to the bare key; returns '' when the value is unusable.
function rm_config_key(string $file): string
{
    $key = basename(trim($file));
    if (str_ends_with(strtolower($key), '.json')) {
        $key = substr($key, 0, -5);
    }
    return config_valid_key($key) ? $key : '';
}

// Remove a JSON key identified by a simple JSONPath expression.
// Supports: $.key, $.key.subkey, $.key[*].subkey (iterates object values too).
function rm_jsonpath_remove(array &$data, string $path): int
{
    if (strncmp($path, '$.', 2) !== 0) {
        return 0;
    }
    $rest   = substr($path, 2);
    $dotPos = strpos($rest, '.');
    $head   = $dotPos !== false ? substr($rest, 0, $dotPos) : $rest;
    $tail   = $dotPos !== false ? '$.' . substr($rest, $dotPos + 1) : null;

    $isWild = str_ends_with($head, '[*]');
    if ($isWild) {
        $head = substr($head, 0, -3);
    }

    if (!array_key_exists($head, $data)) {
        return 0;
    }

    if ($isWild) {
        if ($tail === null) {
            unset($data[$head]);
            return 1;
        }
        if (!is_array($data[$head])) {
            return 0;
        }
        $count = 0;
        foreach ($data[$head] as &$item) {
            if (is_array($item)) {
                $count += rm_jsonpath_remove($item, $tail);
            }
        }
        return $count;
    }

    if ($tail === null) {
        unset($data[$head]);
        return 1;
    }

    if (!is_array($data[$head])) {
        return 0;
    }
    return rm_jsonpath_remove($data[$head], $tail);
}

// ---- SCAN ---------------------------------------------------------------
if ($action === 'scan') {
    $manifest = rm_load_manifest($manifestPath);

    try {
        [, $applied] = rm_db_and_applied();
    } catch (Exception $e) {
        // Table may not exist yet before init_db is run on 2.4.0
        $applied = [];
    }

    $versions = array_keys($manifest);
    usort($versions, 'version_compare');

    $currentVersion = defined('OPENSPARROW_VERSION') ? OPENSPARROW_VERSION : '0.0.0';

    $result = [];
    foreach ($versions as $ver) {
        if (version_compare($ver, $currentVersion, '>')) {
            continue;
        }

        $entry     = $manifest[$ver];
        $isApplied = isset($applied[$ver]);
        $actions   = [];

        foreach ($entry['removed_files'] ?? [] as $relPath) {
            $absPath   = $root . '/' . ltrim((string) $relPath, '/');
            $actions[] = [
                'type'   => 'file_remove',
                'path'   => $relPath,
                'exists' => file_exists($absPath),
                'label'  => 'Remove file: ' . $relPath,
            ];
        }

        foreach ($entry['deprecated_files'] ?? [] as $relPath) {
            $absPath   = $root . '/' . ltrim((string) $relPath, '/');
            $actions[] = [
                'type'   => 'file_deprecated',
                'path'   => $relPath,
                'exists' => file_exists($absPath),
                'label'  => 'Deprecated (info only): ' . $relPath,
            ];
        }

        foreach ($entry['removed_config_keys'] ?? [] as $keyDef) {
            $file    = (string) ($keyDef['file'] ?? '');
            $jpath   = (string) ($keyDef['path'] ?? '');
            $cfgKey  = rm_config_key($file);
            $present = false;
            if ($cfgKey !== '' && $jpath !== '') {
                $cfg = config_get($cfgKey);
                if (is_array($cfg)) {
                    $present = rm_jsonpath_remove($cfg, $jpath) > 0;
                }
            }
            $actions[] = [
                'type'    => 'config_key_remove',
                'file'    => $file,
                'path'    => $jpath,
                'present' => $present,
                'label'   => 'Remove config key ' . $jpath . ' from ' . $file,
            ];
        }

        $appliedData = null;
        if ($isApplied) {
            $row         = $applied[$ver];
            $appliedData = [
                'applied_at' => $row['applied_at'],
                'applied_by' => $row['applied_by'],
                'actions'    => json_decode((string) $row['actions'], true) ?? [],
            ];
        }

        $result[] = [
            'version'      => $ver,
            'status'       => $isApplied ? 'applied' : 'pending',
            'notes'        => (string) ($entry['notes'] ?? ''),
            'actions'      => $actions,
            'applied_data' => $appliedData,
        ];
    }

    echo json_encode([
        'status'          => 'success',
        'versions'        => $result,
        'current_version' => $currentVersion,
    ]);
    exit;
}

// ---- APPLY ---------------------------------------------------------------
if ($action === 'apply') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Invalid request body.']);
        exit;
    }

    $version = trim((string) ($body['version'] ?? ''));
    if ($version === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Missing version.']);
        exit;
    }

    $manifest = rm_load_manifest($manifestPath);
    if (!isset($manifest[$version])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Version not found in manifest.']);
        exit;
    }

    try {
        [$conn, $applied] = rm_db_and_applied();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => 'Database connection failed.']);
        exit;
    }

    if (isset($applied[$version])) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'error' => 'Version already applied.']);
        exit;
    }

    $entry = $manifest[$version];

    // Build ordered action list — same order as scan
    $allActions = [];
    foreach ($entry['removed_files'] ?? [] as $relPath) {
        $allActions[] = ['type' => 'file_remove', 'path' => (string) $relPath];
    }
    foreach ($entry['deprecated_files'] ?? [] as $relPath) {
        $allActions[] = ['type' => 'file_deprecated', 'path' => (string) $relPath];
    }
    foreach ($entry['removed_config_keys'] ?? [] as $keyDef) {
        $allActions[] = [
            'type' => 'config_key_remove',
            'file' => (string) ($keyDef['file'] ?? ''),
            'path' => (string) ($keyDef['path'] ?? ''),
        ];
    }

    // Determine which indices to run; null = all non-deprecated
    $selectedIdxs = $body['selected'] ?? null;
    $toRun        = [];
    if ($selectedIdxs === null) {
        foreach ($allActions as $idx => $a) {
            if ($a['type'] !== 'file_deprecated') {
                $toRun[] = $idx;
            }
        }
    } else {
        foreach ((array) $selectedIdxs as $raw) {
            $idx = (int) $raw;
            if (isset($allActions[$idx])) {
                $toRun[] = $idx;
            }
        }
    }

    $versionSlug = preg_replace('/[^a-zA-Z0-9._-]/', '_', $version);
    $backupDir   = $root . '/storage/migrations_backup/' . $versionSlug;
    $userId      = (int) ($_SESSION['user_id'] ?? 0);
    $log         = [];
    $warnings    = [];

    foreach ($toRun as $idx) {
        $a = $allActions[$idx];

        if ($a['type'] === 'file_remove') {
            $relPath = $a['path'];
            // Paths come from the manifest (whitelist), not user input; realpath validates traversal.
            $absPath = realpath($root . '/' . ltrim($relPath, '/'));
            if ($absPath === false || strncmp($absPath, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
                $warnings[] = 'Unsafe path rejected: ' . $relPath;
                continue;
            }
            if (!file_exists($absPath)) {
                $log[] = ['type' => 'file_remove', 'path' => $relPath, 'status' => 'skipped', 'reason' => 'not_found'];
                continue;
            }
            $backupTarget = $backupDir . '/' . ltrim($relPath, '/');
            if (!is_dir(dirname($backupTarget))) {
                @mkdir(dirname($backupTarget), 0755, true);
            }
            if (!@copy($absPath, $backupTarget)) {
                $warnings[] = 'Backup failed for: ' . $relPath;
                continue;
            }
            if (!@unlink($absPath)) {
                $warnings[] = 'Delete failed for: ' . $relPath;
                continue;
            }
            $log[] = [
                'type'   => 'file_remove',
                'path'   => $relPath,
                'status' => 'done',
                'backup' => 'storage/migrations_backup/' . $versionSlug . '/' . ltrim($relPath, '/'),
            ];
        } elseif ($a['type'] === 'config_key_remove') {
            $jpath  = $a['path'];
            $cfgKey = rm_config_key($a['file']);
            if ($cfgKey === '' || $jpath === '') {
                $warnings[] = 'Invalid config_key_remove entry.';
                continue;
            }
            // Read the row (not just the value) so the write can assert the version it
            // scrubbed — a concurrent admin save must lose, not get silently overwritten.
            $cfgRow = config_get_row($cfgKey);
            if ($cfgRow === null) {
                $log[] = [
                    'type'   => 'config_key_remove',
                    'file'   => $cfgKey,
                    'path'   => $jpath,
                    'status' => 'skipped',
                    'reason' => 'config_key_not_found',
                ];
                continue;
            }
            $cfg     = $cfgRow['value'];
            $removed = rm_jsonpath_remove($cfg, $jpath);
            if ($removed === 0) {
                $log[] = [
                    'type'   => 'config_key_remove',
                    'file'   => $cfgKey,
                    'path'   => $jpath,
                    'status' => 'skipped',
                    'reason' => 'key_not_found',
                ];
                continue;
            }
            // config_save writes the pre-change value into spw_config_log, which is the
            // backup for this action — no file copy under storage/migrations_backup.
            $saved = config_save($cfgKey, $cfg, (int) $cfgRow['version'], $userId ?: null);
            if ($saved['status'] === 'conflict') {
                $warnings[] = 'Config changed concurrently, skipped: ' . $cfgKey;
                continue;
            }
            if ($saved['status'] !== 'ok') {
                $warnings[] = 'Write failed for config key: ' . $cfgKey;
                continue;
            }
            $log[] = [
                'type'          => 'config_key_remove',
                'file'          => $cfgKey,
                'path'          => $jpath,
                'status'        => 'done',
                'removed_count' => $removed,
                'backup'        => 'spw_config_log:' . $cfgKey . ' (version ' . $cfgRow['version'] . ')',
            ];
        } elseif ($a['type'] === 'file_deprecated') {
            $log[] = ['type' => 'file_deprecated', 'path' => $a['path'], 'status' => 'info'];
        }
    }

    // Record in spw_release_migrations
    $tRelMig = sys_table('release_migrations');
    $actJson = (string) json_encode($log);

    $res = @pg_query_params(
        $conn,
        "INSERT INTO $tRelMig (version, applied_by, actions) VALUES (\$1, \$2, \$3)",
        [$version, $userId ?: null, $actJson]
    );

    if (!$res) {
        $raw = pg_last_error($conn);
        error_log('[api_migrations][apply] ' . $raw);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => 'Database write failed.']);
        exit;
    }

    log_user_action($conn, $userId, 'release_migration_applied:' . $version, 'spw_release_migrations', null);

    $response = ['status' => 'success', 'version' => $version, 'actions_log' => $log];
    if (!empty($warnings)) {
        $response['warnings'] = $warnings;
    }
    echo json_encode($response);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'error' => 'Unknown action.']);
