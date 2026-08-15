<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/config_store.php';
require_once __DIR__ . '/../../includes/clickstats.php';

const CLICKSTATS_MAX_EVENTS = 50;

const CLICKSTATS_MAX_ELEMENT = 120;
const CLICKSTATS_MAX_PAGE    = 120;
const CLICKSTATS_MAX_TABLE   = 100;

const CLICKSTATS_MAX_RECORD_ID = 2147483647;

const CLICKSTATS_MAX_ROWS_PER_MIN = 300;

os_api_bootstrap(['connect' => false, 'csrf' => 'manual', 'gate' => false]);

function clickstats_done(int $code = 204): never
{
    http_response_code($code);
    exit;
}

function clickstats_text(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

function clickstats_budget(int $wanted): int
{
    $now     = time();
    $stored  = $_SESSION['clickstats_window'] ?? null;
    $window  = [];
    $used    = 0;
    foreach (is_array($stored) ? $stored : [] as $entry) {
        if (!is_array($entry) || ($now - (int) ($entry[0] ?? 0)) >= 60) {
            continue;
        }
        $window[] = $entry;
        $used    += (int) ($entry[1] ?? 0);
    }

    $take = max(0, min($wanted, CLICKSTATS_MAX_ROWS_PER_MIN - $used));
    if ($take > 0) {
        $window[] = [$now, $take];
    }
    $_SESSION['clickstats_window'] = $window;

    return $take;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    clickstats_done(405);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    clickstats_done(400);
}

os_require_csrf('body', $payload);
require_not_demo();

$cfg = clickstats_settings();
if (!$cfg['enabled']) {
    clickstats_done();
}

$events = $payload['events'] ?? null;
if (!is_array($events) || $events === []) {
    clickstats_done();
}

$userId       = (int) $_SESSION['user_id'];
$trackRecords = !empty($cfg['track_records']);

$params       = [];
$placeholders = [];

$budget = clickstats_budget(min(count($events), CLICKSTATS_MAX_EVENTS));
if ($budget <= 0) {
    clickstats_done();
}

foreach (array_slice($events, 0, $budget) as $input) {
    if (!is_array($input)) {
        continue;
    }
    $element = clickstats_text($input['element'] ?? null);
    if ($element === '') {
        continue;
    }

    $table    = null;
    $recordId = null;
    if ($trackRecords) {
        $candidate = clickstats_text($input['table'] ?? null);

        if ($candidate !== '' && user_can_access('tables', $candidate)) {
            $table = mb_substr($candidate, 0, CLICKSTATS_MAX_TABLE);

            $recordId = filter_var(
                $input['record_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => CLICKSTATS_MAX_RECORD_ID]]
            );
            if ($recordId === false) {
                $recordId = null;
            }
        }
    }

    $page = clickstats_text($input['page'] ?? null);

    $base = count($params);
    $placeholders[] = '($' . ($base + 1) . ', $' . ($base + 2) . ', $' . ($base + 3)
        . ', $' . ($base + 4) . ', $' . ($base + 5) . ')';
    array_push(
        $params,
        $userId,
        mb_substr($element, 0, CLICKSTATS_MAX_ELEMENT),
        $page === '' ? null : mb_substr($page, 0, CLICKSTATS_MAX_PAGE),
        $table,
        $recordId
    );
}

if ($placeholders === []) {
    clickstats_done();
}

$conn   = db_connect();
$target = sys_table('clickstats');
$res    = @pg_query_params(
    $conn,
    "INSERT INTO {$target} (user_id, element, page, table_name, record_id) VALUES "
    . implode(', ', $placeholders),
    $params
);
if (!$res) {
    error_log('[OpenSparrow] clickstats insert failed: ' . pg_last_error($conn));
    clickstats_done(500);
}

clickstats_done();
