<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.
//
// api/notes.php — Private user notepad (User menu > Notes), optionally linked to a
// record via related_table/related_id, with an optional reminder_date. Reminders are
// delivered by cron/cron_notifications.php into spw_users_notifications (the bell icon).
// Auth gate: session + UA enforcement; CSRF via X-CSRF-Token header (default os_api_bootstrap gate)
// Action routing via os_api_action()/os_api_dispatch(): list, list_records, add, update, delete —
// every query scoped to the caller's own user_id, never trusting a client-supplied one.
// sys_table('notes'); parameterized queries.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$conn = os_api_bootstrap();

const NOTE_BODY_MAX_LEN = 4000;
const NOTE_RECORD_PICKER_LIMIT = 500;

['action' => $action, 'body' => $body] = os_api_action();

os_api_dispatch($action, [
    'list'         => fn() => notes_action_list($conn),
    'list_records' => fn() => notes_action_list_records($conn),
    'add'          => fn() => notes_action_add($conn, $body),
    'update'       => fn() => notes_action_update($conn, $body),
    'delete'       => fn() => notes_action_delete($conn, $body),
], 'api_notes');

// Validates an optional (related_table, related_id) pair: both present and sane, or
// both absent. Returns [related_table, related_id] with null entries when unlinked.
function validatedRelation(array $src): array
{
    $relatedTable = trim($src['related_table'] ?? '');
    $relatedId    = isset($src['related_id']) && $src['related_id'] !== '' ? (int)$src['related_id'] : null;

    if ($relatedTable === '' && $relatedId === null) {
        return [null, null];
    }
    if ($relatedTable === '' || $relatedId === null || $relatedId <= 0) {
        jsonError('related_table and related_id must be provided together.', 400);
    }

    return [validatedTable($relatedTable, 'related_table'), $relatedId];
}

// Validates an optional reminder date+time, now or later. Accepts the datetime-local
// wire format (Y-m-d\TH:i[:s]), a space-separated variant, and a bare Y-m-d (treated as
// midnight) for backward compatibility. Returns 'Y-m-d H:i:00', or null when unset.
function validatedReminderDate(array $src): ?string
{
    $raw = trim($src['reminder_date'] ?? '');
    if ($raw === '') {
        return null;
    }

    $normalized = str_replace('T', ' ', $raw);
    $date = null;
    $hasTime = true;
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
        $parsed = DateTime::createFromFormat($format, $normalized);
        if ($parsed && $parsed->format($format) === $normalized) {
            $date = $parsed;
            $hasTime = $format !== 'Y-m-d';
            break;
        }
    }
    if (!$date) {
        jsonError('reminder_date must be a valid date/time (YYYY-MM-DDTHH:MM).', 400);
    }
    // A bare date means "that day", not "that day at the current clock time".
    if (!$hasTime) {
        $date->setTime(0, 0);
        if ($date->format('Y-m-d') < date('Y-m-d')) {
            jsonError('reminder_date cannot be in the past.', 400);
        }
    } elseif ($date->format('Y-m-d H:i:s') < date('Y-m-d H:i:s')) {
        jsonError('reminder_date cannot be in the past.', 400);
    }

    return $date->format('Y-m-d H:i:00');
}

function validatedBody(array $src): string
{
    $rawBody = trim($src['body'] ?? '');
    if ($rawBody === '') {
        jsonError('Note body cannot be empty.', 400);
    }
    if (mb_strlen($rawBody) > NOTE_BODY_MAX_LEN) {
        jsonError('Note exceeds maximum length of ' . NOTE_BODY_MAX_LEN . ' characters.', 400);
    }

    return $rawBody;
}

// Record picker for the "link to a record" form field — mirrors the Files module's
// table+record dropdown pair (public/api/files.php files_action_get_related_records): given a
// table name, returns its most recent rows as {id, label}. Label columns come from the
// same heuristic as the "My records" panel (record_label_columns() in api_helpers.php)
// since spw_notes has no per-relation column config like the Files module does.
function notes_action_list_records($conn): void
{
    $table = validatedTable(trim($_GET['table'] ?? ''), 'table');

    require_once __DIR__ . '/../../includes/config_store.php';
    $schema   = config_get('schema') ?? [];
    $tableCfg = $schema['tables'][$table];
    $pgSchema = $tableCfg['schema'] ?? 'public';

    $userRecordsCfg = config_get('user_records') ?? [];
    $configuredCols = is_array($userRecordsCfg['columns'][$table] ?? null) ? $userRecordsCfg['columns'][$table] : [];
    $labelSql       = record_label_sql($tableCfg, $configuredCols);

    $sql = sprintf(
        'SELECT id, %s AS label FROM %s.%s ORDER BY id DESC LIMIT %d',
        $labelSql,
        pg_ident($pgSchema),
        pg_ident($table),
        NOTE_RECORD_PICKER_LIMIT
    );
    $res = pg_query($conn, $sql);
    if (!$res) {
        error_log('api_notes notes_action_list_records failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $records = [];
    while ($row = pg_fetch_assoc($res)) {
        $label = trim((string)($row['label'] ?? ''));
        $records[] = [
            'id'    => (int)$row['id'],
            'label' => $label !== '' ? $label : ('#' . $row['id']),
        ];
    }

    jsonSuccess(['records' => $records]);
}

function notes_action_list($conn): void
{
    $userId = (int)$_SESSION['user_id'];
    $sql = "
        SELECT id, body, related_table, related_id, reminder_date, created_at, updated_at
        FROM " . sys_table('notes') . "
        WHERE user_id = \$1 AND deleted_at IS NULL
        ORDER BY reminder_date NULLS LAST, created_at DESC
    ";
    $res = pg_query_params($conn, $sql, [$userId]);
    if (!$res) {
        error_log('api_notes notes_action_list failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $notes = [];
    while ($row = pg_fetch_assoc($res)) {
        $row['related_id'] = $row['related_id'] !== null ? (int)$row['related_id'] : null;
        // PG renders timestamps as 'Y-m-d H:i:s'; the client wants minute precision.
        if (!empty($row['reminder_date'])) {
            $row['reminder_date'] = substr(str_replace(' ', 'T', $row['reminder_date']), 0, 16);
        }
        $notes[] = $row;
    }

    jsonSuccess(['notes' => $notes]);
}

function notes_action_add($conn, array $body): void
{
    $userId = (int)$_SESSION['user_id'];
    $noteBody = validatedBody($body);
    [$relatedTable, $relatedId] = validatedRelation($body);
    $reminderDate = validatedReminderDate($body);

    $sql = "
        INSERT INTO " . sys_table('notes') . "
            (user_id, body, related_table, related_id, reminder_date)
        VALUES (\$1, \$2, \$3, \$4, \$5)
        RETURNING id, created_at
    ";
    $res = pg_query_params($conn, $sql, [$userId, $noteBody, $relatedTable, $relatedId, $reminderDate]);
    if (!$res) {
        error_log('api_notes notes_action_add failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $inserted = pg_fetch_assoc($res);
    log_user_action($conn, $userId, 'NOTE_ADD', 'notes', (int)$inserted['id']);

    jsonSuccess(['note' => [
        'id'            => (int)$inserted['id'],
        'body'          => $noteBody,
        'related_table' => $relatedTable,
        'related_id'    => $relatedId,
        'reminder_date' => $reminderDate !== null ? substr(str_replace(' ', 'T', $reminderDate), 0, 16) : null,
        'created_at'    => $inserted['created_at'],
        'updated_at'    => null,
    ]], 201);
}

function notes_action_update($conn, array $body): void
{
    $userId = (int)$_SESSION['user_id'];
    $id     = (int)($body['id'] ?? 0);
    if ($id <= 0) {
        jsonError('id is required.', 400);
    }

    $noteBody = validatedBody($body);
    [$relatedTable, $relatedId] = validatedRelation($body);
    $reminderDate = validatedReminderDate($body);

    $sql = "
        UPDATE " . sys_table('notes') . "
        SET body = \$1, related_table = \$2, related_id = \$3, reminder_date = \$4, updated_at = NOW()
        WHERE id = \$5 AND user_id = \$6 AND deleted_at IS NULL
        RETURNING id
    ";
    $res = pg_query_params($conn, $sql, [$noteBody, $relatedTable, $relatedId, $reminderDate, $id, $userId]);
    if (!$res) {
        error_log('api_notes notes_action_update failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    if (pg_num_rows($res) === 0) {
        jsonError('Note not found.', 404);
    }

    log_user_action($conn, $userId, 'NOTE_UPDATE', 'notes', $id);
    jsonSuccess(['updated' => true]);
}

function notes_action_delete($conn, array $body): void
{
    $userId = (int)$_SESSION['user_id'];
    $id     = (int)($body['id'] ?? 0);
    if ($id <= 0) {
        jsonError('id is required.', 400);
    }

    $sql = "
        UPDATE " . sys_table('notes') . "
        SET deleted_at = NOW()
        WHERE id = \$1 AND user_id = \$2 AND deleted_at IS NULL
    ";
    $res = pg_query_params($conn, $sql, [$id, $userId]);
    if (!$res) {
        error_log('api_notes notes_action_delete failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    if (pg_affected_rows($res) === 0) {
        jsonError('Note not found.', 404);
    }

    log_user_action($conn, $userId, 'NOTE_DELETE', 'notes', $id);
    jsonSuccess(['deleted' => true]);
}
