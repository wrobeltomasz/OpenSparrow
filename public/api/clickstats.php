<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// api/clickstats.php — click statistics collector endpoint (Admin → System → Click Statistics)
// POST only; auth gate: session + UA enforcement via os_api_bootstrap(); CSRF from the
// request body (navigator.sendBeacon cannot set headers); require_not_demo() on the write.
// Silently no-ops with 204 whenever the module is disabled, so a stale page left open
// after the admin switched it off stops writing immediately.
// Batched payload: {csrf_token, events:[{element, page, table, record_id}]} — capped at
// CLICKSTATS_MAX_EVENTS rows, written with a single multi-row INSERT into sys_table('clickstats').

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/config_store.php';

// Rows accepted per request. Mirrors MAX_BUFFER in assets/js/util/clickstats.js;
// a client sending more is not trusted to stop at the client-side cap.
const CLICKSTATS_MAX_EVENTS = 50;

// varchar widths of spw_clickstats.element / .page / .table_name — truncate rather
// than let Postgres reject the whole batch over one long label. Every string column
// written below needs an entry here: a value that overflows its column takes the
// entire multi-row INSERT down with it, not just its own row.
const CLICKSTATS_MAX_ELEMENT = 120;
const CLICKSTATS_MAX_PAGE    = 120;
const CLICKSTATS_MAX_TABLE   = 100;

// Upper bound of the int4 record_id column.
const CLICKSTATS_MAX_RECORD_ID = 2147483647;

// Ceiling on rows one session may store per minute. Retention is manual (the Log
// tab trims or clears, there is no cron worker), so without a ceiling a client that
// ignores the collector's own pacing could grow the table until someone notices.
// 300 is five clicks a second sustained for a full minute: far past what a person
// produces, far below the 600/min an unthrottled client could push through the
// MAX_BUFFER/FLUSH_MS pacing, so it never trims an honest session.
//
// Deliberately per session, not per user: the window lives in the session itself,
// which costs nothing (the session is already open and written) and needs no
// filesystem or APCu state on a fire-and-forget path. A user holding several
// sessions gets that multiple of the budget — this is a brake on runaway volume,
// not an access control.
const CLICKSTATS_MAX_ROWS_PER_MIN = 300;

// connect=false: nothing here needs a connection until the flag has been checked and
// there are rows to write. (config_get() opens one itself on an APCu cache miss — the
// real "costs nothing when off" guarantee is that no page emits the collector at all,
// so this endpoint is not called in the first place.)
// csrf=manual: sendBeacon sends no headers, so the token travels in the body and is
// validated here instead.
// gate=false: no request-supplied name selects anything. The one protected name in the
// payload is a table label written into a column, and it is checked against the caller's
// own access scope before it is stored (see below) — there is nothing else to gate.
os_api_bootstrap(['connect' => false, 'csrf' => 'manual', 'gate' => false]);

// Statistics are fire-and-forget: the collector ignores the response, so there is no
// body to return in any branch. 204 keeps that explicit and cheap.
function clickstats_done(int $code = 204): never
{
    http_response_code($code);
    exit;
}

// Sliding one-minute window over the rows this session has already stored.
// Returns how many of $wanted may still be written — 0 once the budget is spent,
// in which case the batch is dropped silently like every other rejection here.
//
// Charges the whole slice up front rather than the rows that survive validation.
// That can over-charge by a few rows on a batch full of unusable events, which is
// the harmless direction for a brake: it never rejects a request outright, only
// trims how much of one very noisy minute reaches the table.
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

// The off switch. Checked on every request, not just at page render: a page loaded
// while the module was on must stop being recorded the moment it is turned off.
$cfg = config_get('clickstats') ?? [];
if (empty($cfg['enabled'])) {
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
    $element = trim((string) ($input['element'] ?? ''));
    if ($element === '') {
        continue;
    }

    $table    = null;
    $recordId = null;
    if ($trackRecords) {
        $candidate = trim((string) ($input['table'] ?? ''));
        // The table name is stored as a plain label, never used as an identifier — but a
        // user must not be able to seed the admin's log with names from tables they cannot
        // open. Out of scope is dropped, not rejected: telemetry never fails a request.
        //
        // The check narrows nothing for an unrestricted user: user_can_access() answers
        // true for any string once user_allowed_items() returns null, which is the default
        // state. So the value still has to be truncated like element and page — an
        // over-long label would otherwise take the whole batch's INSERT down with it.
        if ($candidate !== '' && user_can_access('tables', $candidate)) {
            $table = mb_substr($candidate, 0, CLICKSTATS_MAX_TABLE);
            // record_id is an int4 column. is_numeric() alone would let "1e20" or a
            // 64-bit value through, and Postgres then rejects the whole multi-row
            // INSERT over one bad id — losing the entire batch. Out of range is
            // simply "no record in context", like every other unusable value here.
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

    $page = trim((string) ($input['page'] ?? ''));

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

// One multi-row INSERT for the whole batch — the point of buffering on the client is
// lost if the server then runs a statement per click.
$conn   = db_connect();
$target = sys_table('clickstats');
$res    = @pg_query_params(
    $conn,
    "INSERT INTO {$target} (user_id, element, page, table_name, record_id) VALUES "
    . implode(', ', $placeholders),
    $params
);
if (!$res) {
    // A missing table (migration not run) or any other write failure must stay invisible
    // to the user: this is telemetry, not their work. Logged for the operator.
    error_log('[OpenSparrow] clickstats insert failed: ' . pg_last_error($conn));
    clickstats_done(500);
}

clickstats_done();
