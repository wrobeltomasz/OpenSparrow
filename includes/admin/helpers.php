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
 * Longest retention window a purge request may ask for, in days (10 years).
 * Beyond this the value is a mistyped one, not a real window — and an unbounded
 * day count overflows the '<n> days'::interval that admin_purge_log() builds.
 */
const ADMIN_PURGE_MAX_DAYS = 3650;

/**
 * The retention window a purge request asked for, or null when it named none
 * (the caller's own default then applies).
 *
 * A 'days' that is present but unusable is REJECTED, never coerced. The previous
 * `max(1, (int) $raw)` turned every unusable value — 0, -5, '', 'abc', true — into
 * "delete everything older than one day", which is a near-total wipe of the log
 * from input that was never a day count. In the one module whose no-window branch
 * clears the whole table (clickstats), the same coercion would have been total.
 *
 * Absence must stay distinguishable from a bad value, so only a missing key or an
 * explicit null count as "not requested": '' is a rejected value, not a default.
 *
 * @throws AdminApiMessage when 'days' is present and not a usable day count.
 */
function admin_purge_days(array $input): ?int
{
    if (!array_key_exists('days', $input) || $input['days'] === null) {
        return null;
    }

    // The type test is not redundant: FILTER_VALIDATE_INT turns true into 1, and a
    // bare true was never a day count.
    $raw  = $input['days'];
    $days = is_int($raw) || is_string($raw)
        ? filter_var(
            $raw,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => ADMIN_PURGE_MAX_DAYS]]
        )
        : false;

    if ($days === false) {
        throw new AdminApiMessage(
            'Retention window must be a whole number of days between 1 and '
            . ADMIN_PURGE_MAX_DAYS . '.'
        );
    }
    return $days;
}

/** What admin_purge_scope() returns for "clear the whole table". */
const ADMIN_PURGE_ALL = 'all';

/**
 * What a purge request asked for: a window in days, or ADMIN_PURGE_ALL.
 *
 * For callers whose no-window branch is destructive — clickstats clears its entire
 * log — absence must not be an instruction. "Delete everything" is the largest
 * operation the module has, so it has to be asked for explicitly with {"all": true}
 * and can never be the reading of a field that was left out, misspelled or dropped
 * by a client bug. A request naming neither is refused rather than guessed at.
 *
 * Only a real boolean true counts: 1, "true" and "yes" are near-misses of exactly
 * the kind an explicit flag exists to stop, so they are refused too.
 *
 * Naming both is refused as well. It is a caller bug either way, and the two
 * possible readings differ by the whole table.
 *
 * Callers whose no-window branch is harmless (cron, ETL, anonymization: they fall
 * back to their own default window) keep using admin_purge_days() instead.
 *
 * @throws AdminApiMessage when the request names neither, both, or an unusable one.
 */
function admin_purge_scope(array $input): int|string
{
    // Delegated, so a bad window is rejected identically wherever it arrives.
    $days = admin_purge_days($input);
    $all  = $input['all'] ?? null;

    if ($days !== null && $all !== null) {
        throw new AdminApiMessage('Send either a retention window or "all": true, not both.');
    }
    if ($days !== null) {
        return $days;
    }
    if ($all === true) {
        return ADMIN_PURGE_ALL;
    }
    throw new AdminApiMessage(
        'A purge must ask for a retention window in days, or for "all": true to clear everything.'
    );
}

/**
 * Delete log rows older than $days and emit the standard {deleted: n} response.
 * $timeColumn is a trusted literal from the calling module, never user input.
 * $afterDelete receives ($conn, $days) for modules that must sweep a companion
 * table with the same retention window. Never returns.
 *
 * Takes the window already resolved, for callers that worked it out for themselves.
 * admin_purge_scope() callers are the ones that do: their module also has a "clear
 * everything" branch to tell apart, so they have already read and validated the
 * body by the time they get here and must not have it re-read behind them.
 * Callers that only ever want "the window this request asked for" use
 * admin_purge_log() below.
 *
 * @throws AdminApiMessage when $days is not a usable window.
 */
function admin_purge_older_than(
    string $table,
    int $days,
    string $context,
    string $timeColumn = 'started_at',
    ?callable $afterDelete = null
): never {
    // Checked before anything else, and never coerced. Both callers resolve the
    // window through admin_purge_days()/admin_purge_scope(), which reject anything
    // outside 1..ADMIN_PURGE_MAX_DAYS — but this entry point takes the number on
    // trust, and "older than 0 days" is NOW(), i.e. the whole table (a negative
    // window is worse still). A future caller passing request input straight
    // through must not be one typo away from that. Same reasoning and same bounds
    // as admin_purge_days(); this is that guard on the path which bypasses it.
    if ($days < 1 || $days > ADMIN_PURGE_MAX_DAYS) {
        throw new AdminApiMessage(
            'Retention window must be a whole number of days between 1 and '
            . ADMIN_PURGE_MAX_DAYS . '.'
        );
    }

    $conn = admin_conn();
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
 * admin_purge_older_than() with the retention window read from the request body.
 * Replaces four near-identical purge blocks.
 *
 * $defaultDays applies only when the request names no window at all; a window it
 * does name is validated by admin_purge_days() — see the note there on why an
 * unusable value is rejected instead of falling back to one day.
 */
function admin_purge_log(
    string $table,
    int $defaultDays,
    string $context,
    string $timeColumn = 'started_at',
    ?callable $afterDelete = null
): never {
    admin_purge_older_than(
        $table,
        admin_purge_days(admin_input()) ?? max(1, $defaultDays),
        $context,
        $timeColumn,
        $afterDelete
    );
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
    if ($itemId !== '') {
        // Fail closed: the workers treat a missing id as "process everything", so silently
        // dropping a malformed id would turn "run this one job" into a full run.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $itemId)) {
            admin_err('Invalid item id.');
        }
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
