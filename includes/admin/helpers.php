<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api_helpers.php';
require_once __DIR__ . '/../admin_api_errors.php';
require_once __DIR__ . '/../config_store.php';
require_once __DIR__ . '/../bootstrap.php';

use App\Exception\ControlFlowException;
use App\Exception\HttpException;
use App\Exception\ResponseException;

function admin_conn(): \PgSql\Connection
{
    return db_connect();
}

function admin_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function admin_input(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function admin_ok(array $extra = []): never
{
    throw ResponseException::json(['status' => 'success'] + $extra, 0);
}

function admin_err(string $message, int $code = 0): never
{
    throw HttpException::fromStatus($code, $message, ['status' => 'error', 'error' => $message]);
}

function admin_try(callable $body): never
{
    try {
        $body();
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        throw ResponseException::json(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

function admin_fetch_all(\PgSql\Result $queryResult): array
{
    $rows = [];
    while ($row = pg_fetch_assoc($queryResult)) {
        $rows[] = $row;
    }
    return $rows;
}

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

function admin_expected_version(array $data): ?int
{
    return isset($data['version']) && is_numeric($data['version']) ? (int) $data['version'] : null;
}

function admin_require_log_table(\PgSql\Connection $conn, string $table): void
{
    if (@pg_query($conn, "SELECT 1 FROM {$table} LIMIT 0")) {
        return;
    }
    throw ResponseException::json([
        'status' => 'success',
        'rows'   => [],
        'note'   => 'Run Initialize System Tables to create the log table.',
    ], 0);
}

const ADMIN_PURGE_MAX_DAYS = 3650;

function admin_purge_days(array $input): ?int
{
    if (!array_key_exists('days', $input) || $input['days'] === null) {
        return null;
    }

    $rawDays  = $input['days'];
    $days = is_int($rawDays) || is_string($rawDays)
        ? filter_var(
            $rawDays,
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

const ADMIN_PURGE_ALL = 'all';

function admin_purge_scope(array $input): int|string
{
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

function admin_purge_older_than(
    string $table,
    int $days,
    string $context,
    string $timeColumn = 'started_at',
    ?callable $afterDelete = null
): never {
    if ($days < 1 || $days > ADMIN_PURGE_MAX_DAYS) {
        throw new AdminApiMessage(
            'Retention window must be a whole number of days between 1 and '
            . ADMIN_PURGE_MAX_DAYS . '.'
        );
    }

    $conn = admin_conn();
    $queryResult  = @pg_query_params(
        $conn,
        "DELETE FROM {$table} WHERE " . pg_ident($timeColumn) . " < NOW() - ($1 || ' days')::interval",
        [$days]
    );
    if (!$queryResult) {
        admin_db_fail($conn, $context);
    }
    $deleted = pg_affected_rows($queryResult);
    if ($afterDelete !== null) {
        $afterDelete($conn, $days);
    }
    admin_ok(['deleted' => $deleted]);
}

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

function admin_read_settings(): array
{
    return config_get('settings') ?? [];
}

function admin_write_settings(array $settings): bool
{
    return config_save('settings', $settings, null, admin_user_id())['status'] === 'ok';
}

function admin_save_settings(array $settings): void
{
    if (!admin_write_settings($settings)) {
        admin_err('Could not save settings.');
    }
}

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
    throw ResponseException::sent();
}
