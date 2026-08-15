<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\HttpException;
use App\Exception\ResponseException;

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/config_store.php';

os_api_bootstrap(['connect' => false, 'role' => 'admin']);

$action = $_GET['action'] ?? '';

if ($action === 'apply') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw HttpException::fromStatus(
            405,
            'Method Not Allowed.',
            ['status' => 'error', 'error' => 'Method Not Allowed.'],
        );
    }
    if (DEMO_MODE) {
        throw HttpException::fromStatus(
            403,
            'Blocked in demo mode.',
            ['status' => 'error', 'error' => 'Blocked in demo mode.'],
        );
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
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function rm_db_and_applied(): array
{
    require_once __DIR__ . '/../../includes/db.php';
    $conn    = db_connect();
    $releaseMigrationsTable = sys_table('release_migrations');
    $sql     = "SELECT version, applied_at, applied_by, actions FROM $releaseMigrationsTable ORDER BY applied_at ASC";
    $queryResult     = @pg_query($conn, $sql);
    $output     = [];
    if ($queryResult) {
        while ($row = pg_fetch_assoc($queryResult)) {
            $output[$row['version']] = $row;
        }
    }
    return [$conn, $output];
}

function rm_config_key(string $file): string
{
    $key = basename(trim($file));
    if (str_ends_with(strtolower($key), '.json')) {
        $key = substr($key, 0, -5);
    }
    return config_valid_key($key) ? $key : '';
}

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

if ($action === 'scan') {
    $manifest = rm_load_manifest($manifestPath);

    try {
        [, $applied] = rm_db_and_applied();
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Exception $exception) {
        $applied = [];
    }

    $versions = array_keys($manifest);
    usort($versions, 'version_compare');

    $currentVersion = defined('OPENSPARROW_VERSION') ? OPENSPARROW_VERSION : '0.0.0';

    $result = [];
    foreach ($versions as $versionKey) {
        if (version_compare($versionKey, $currentVersion, '>')) {
            continue;
        }

        $entry     = $manifest[$versionKey];
        $isApplied = isset($applied[$versionKey]);
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

        foreach ($entry['removed_config_keys'] ?? [] as $keyDefinition) {
            $file    = (string) ($keyDefinition['file'] ?? '');
            $jpath   = (string) ($keyDefinition['path'] ?? '');
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
            $row         = $applied[$versionKey];
            $appliedData = [
                'applied_at' => $row['applied_at'],
                'applied_by' => $row['applied_by'],
                'actions'    => json_decode((string) $row['actions'], true) ?? [],
            ];
        }

        $result[] = [
            'version'      => $versionKey,
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
    throw ResponseException::sent();
}

if ($action === 'apply') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        throw HttpException::fromStatus(
            400,
            'Invalid request body.',
            ['status' => 'error', 'error' => 'Invalid request body.'],
        );
    }

    $version = trim((string) ($body['version'] ?? ''));
    if ($version === '') {
        throw HttpException::fromStatus(400, 'Missing version.', ['status' => 'error', 'error' => 'Missing version.']);
    }

    $manifest = rm_load_manifest($manifestPath);
    if (!isset($manifest[$version])) {
        throw HttpException::fromStatus(
            400,
            'Version not found in manifest.',
            ['status' => 'error', 'error' => 'Version not found in manifest.'],
        );
    }

    try {
        [$conn, $applied] = rm_db_and_applied();
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Exception $exception) {
        throw HttpException::fromStatus(
            500,
            'Database connection failed.',
            ['status' => 'error', 'error' => 'Database connection failed.'],
        );
    }

    if (isset($applied[$version])) {
        throw HttpException::fromStatus(
            409,
            'Version already applied.',
            ['status' => 'error', 'error' => 'Version already applied.'],
        );
    }

    $entry = $manifest[$version];

    $allActions = [];
    foreach ($entry['removed_files'] ?? [] as $relPath) {
        $allActions[] = ['type' => 'file_remove', 'path' => (string) $relPath];
    }
    foreach ($entry['deprecated_files'] ?? [] as $relPath) {
        $allActions[] = ['type' => 'file_deprecated', 'path' => (string) $relPath];
    }
    foreach ($entry['removed_config_keys'] ?? [] as $keyDefinition) {
        $allActions[] = [
            'type' => 'config_key_remove',
            'file' => (string) ($keyDefinition['file'] ?? ''),
            'path' => (string) ($keyDefinition['path'] ?? ''),
        ];
    }

    $selectedIndexes = $body['selected'] ?? null;
    $toRun        = [];
    if ($selectedIndexes === null) {
        foreach ($allActions as $index => $migrationAction) {
            if ($migrationAction['type'] !== 'file_deprecated') {
                $toRun[] = $index;
            }
        }
    } else {
        foreach ((array) $selectedIndexes as $raw) {
            $index = (int) $raw;
            if (isset($allActions[$index])) {
                $toRun[] = $index;
            }
        }
    }

    $versionSlug = preg_replace('/[^a-zA-Z0-9._-]/', '_', $version);
    $backupDir   = $root . '/storage/migrations_backup/' . $versionSlug;
    $userId      = (int) ($_SESSION['user_id'] ?? 0);
    $log         = [];
    $warnings    = [];

    foreach ($toRun as $index) {
        $migrationAction = $allActions[$index];

        if ($migrationAction['type'] === 'file_remove') {
            $relPath = $migrationAction['path'];

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
        } elseif ($migrationAction['type'] === 'config_key_remove') {
            $jpath  = $migrationAction['path'];
            $cfgKey = rm_config_key($migrationAction['file']);
            if ($cfgKey === '' || $jpath === '') {
                $warnings[] = 'Invalid config_key_remove entry.';
                continue;
            }

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
        } elseif ($migrationAction['type'] === 'file_deprecated') {
            $log[] = ['type' => 'file_deprecated', 'path' => $migrationAction['path'], 'status' => 'info'];
        }
    }

    $releaseMigrationsTable = sys_table('release_migrations');
    $actJson = (string) json_encode($log);

    $queryResult = @pg_query_params(
        $conn,
        "INSERT INTO $releaseMigrationsTable (version, applied_by, actions) VALUES (\$1, \$2, \$3)",
        [$version, $userId ?: null, $actJson]
    );

    if (!$queryResult) {
        $raw = pg_last_error($conn);
        error_log('[api_migrations][apply] ' . $raw);
        throw HttpException::fromStatus(
            500,
            'Database write failed.',
            ['status' => 'error', 'error' => 'Database write failed.'],
        );
    }

    log_user_action($conn, $userId, 'release_migration_applied:' . $version, 'spw_release_migrations', null);

    $response = ['status' => 'success', 'version' => $version, 'actions_log' => $log];
    if (!empty($warnings)) {
        $response['warnings'] = $warnings;
    }
    throw ResponseException::encoded($response);
}

http_response_code(400);
echo json_encode(['status' => 'error', 'error' => 'Unknown action.']);
