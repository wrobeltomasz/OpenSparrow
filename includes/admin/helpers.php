<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/admin/helpers.php — shared helpers for the admin api.php modules.
// Required once by public/admin/api.php before dispatch, so every module under
// includes/admin/ can use these without its own require.
//
// The admin modules are procedural scripts that each emit one JSON response and
// exit; these helpers keep that contract (most of them are `never`-returning)
// while removing the response/connection/purge/save blocks that were previously
// copied verbatim across 15+ files.
//
// The response envelope is {status: 'success'|'error', ...} — the same shape the
// admin SPA has always consumed. Content-Type is set once by the front
// controller, not per action.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api_helpers.php';
require_once __DIR__ . '/../admin_api_errors.php';
require_once __DIR__ . '/../config_store.php';

/**
 * The shared PostgreSQL connection for this request. Replaces the
 * `require_once db.php; $conn = db_connect();` pair the modules repeated ~47
 * times. db_connect() already returns a per-request singleton, so calling this
 * repeatedly is free.
 */
function admin_conn(): \PgSql\Connection
{
    return db_connect();
}

/**
 * Id of the admin performing the request, or null when the session carries
 * none. Used for config_save() attribution and audit logging.
 */
function admin_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Decoded JSON request body, or [] when the body is absent or not an object.
 */
function admin_input(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/**
 * Emit a success envelope and exit. $extra is merged into the response.
 */
function admin_ok(array $extra = []): never
{
    echo json_encode(['status' => 'success'] + $extra);
    exit;
}

/**
 * Emit an error envelope and exit. $code 0 leaves the HTTP status untouched,
 * which is what most admin actions historically did (200 + error body).
 */
function admin_err(string $message, int $code = 0): never
{
    if ($code !== 0) {
        http_response_code($code);
    }
    echo json_encode(['status' => 'error', 'error' => $message]);
    exit;
}

/**
 * Run an action body with the module-standard error envelope, then exit.
 * Replaces the
 *   try { ... } catch (Throwable $e) { echo json_encode([...admin_error_message($e)]); } exit;
 * tail that appeared ~50 times. Deliberate AdminApiMessage text reaches the
 * client; anything else is logged and genericized by admin_error_message().
 */
function admin_try(callable $body): never
{
    try {
        $body();
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

/**
 * Collect every row of a result set as an assoc array.
 */
function admin_fetch_all(\PgSql\Result $res): array
{
    $rows = [];
    while ($row = pg_fetch_assoc($res)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Persist a config key with optimistic locking and emit the standard response.
 * Replaces three verbatim copies (anonymization / etl / etl_flow) of the
 * conflict + failure + {version} success tail. Never returns.
 */
function admin_config_save_versioned(
    string $key,
    array $config,
    ?int $expectedVersion,
    string $failMessage = 'Failed to save config.'
): never {
    $result = config_save($key, $config, $expectedVersion, admin_user_id());
    if ($result['status'] === 'conflict') {
        admin_err('Config was modified by someone else — reload and retry.');
    }
    if ($result['status'] !== 'ok') {
        admin_err($result['error'] ?? $failMessage);
    }
    admin_ok(['version' => $result['version']]);
}

/**
 * Reads the optimistic-lock version the editor echoed back, if any.
 */
function admin_expected_version(array $data): ?int
{
    return isset($data['version']) && is_numeric($data['version']) ? (int) $data['version'] : null;
}

/**
 * Emit the "log table not created yet" response and exit when $table is
 * missing, so a fresh install shows an empty log with a hint instead of a
 * database error. Returns normally when the table exists.
 */
function admin_require_log_table(\PgSql\Connection $conn, string $table): void
{
    if (@pg_query($conn, "SELECT 1 FROM {$table} LIMIT 0")) {
        return;
    }
    echo json_encode([
        'status' => 'success',
        'rows'   => [],
        'note'   => 'Run Initialize System Tables to create the log table.',
    ]);
    exit;
}

/**
 * Delete log rows older than the requested retention window and emit the
 * standard {deleted: n} response. Replaces four near-identical purge blocks.
 * $timeColumn is a trusted literal from the calling module, never user input.
 * $afterDelete receives ($conn, $days) for modules that must sweep a companion
 * table with the same retention window. Never returns.
 */
function admin_purge_log(
    string $table,
    int $defaultDays,
    string $context,
    string $timeColumn = 'started_at',
    ?callable $afterDelete = null
): never {
    $conn = admin_conn();
    $days = max(1, (int) (admin_input()['days'] ?? $defaultDays));
    $res  = @pg_query_params(
        $conn,
        "DELETE FROM {$table} WHERE " . pg_ident($timeColumn) . " < NOW() - ($1 || ' days')::interval",
        [$days]
    );
    if (!$res) {
        admin_db_fail($conn, $context);
    }
    $deleted = pg_affected_rows($res);
    if ($afterDelete !== null) {
        $afterDelete($conn, $days);
    }
    admin_ok(['deleted' => $deleted]);
}

/**
 * The "settings" spw_config key. Read as a plain array; the settings module
 * mutates individual keys and hands the whole map back to admin_save_settings().
 */
function admin_read_settings(): array
{
    return config_get('settings') ?? [];
}

function admin_write_settings(array $settings): bool
{
    return config_save('settings', $settings, null, admin_user_id())['status'] === 'ok';
}

/**
 * Persist the settings map, emitting the standard failure response and exiting
 * when the write is rejected. Replaces eight copies of the same
 * write-then-echo-'Could not save settings.' block in settings.php.
 */
function admin_save_settings(array $settings): void
{
    if (!admin_write_settings($settings)) {
        admin_err('Could not save settings.');
    }
}

/**
 * Validate and invoke a cron worker script with an optional single-item id,
 * emitting the captured output as JSON. The run status reflects the worker's
 * exit code — the older inline copies in cron.php / anonymization.php always
 * reported success regardless of how the worker ended. Never returns.
 *
 * $extraArgs are trusted literals supplied by the calling module (e.g. 'dry');
 * $itemId is request-supplied and is both pattern-checked and shell-escaped.
 */
function admin_run_cron_script(
    string $absScriptPath,
    string $notFoundMessage,
    string $itemId = '',
    array $extraArgs = []
): never {
    $cronScript = realpath($absScriptPath);
    if ($cronScript === false || !is_readable($cronScript)) {
        admin_err($notFoundMessage);
    }
    if (!function_exists('exec')) {
        admin_err('exec() is disabled on this server.');
    }

    $args = 'admin';
    if ($itemId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $itemId)) {
        $args .= ' ' . escapeshellarg($itemId);
    }
    foreach ($extraArgs as $extra) {
        $args .= ' ' . escapeshellarg((string) $extra);
    }

    $lines = [];
    $code  = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($cronScript) . ' ' . $args . ' 2>&1', $lines, $code);
    echo json_encode([
        'status'    => $code === 0 ? 'success' : 'error',
        'output'    => implode("\n", $lines),
        'exit_code' => $code,
    ]);
    exit;
}
