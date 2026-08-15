<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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

function validatedRelation(array $source): array
{
    $relatedTable = trim($source['related_table'] ?? '');
    $relatedId    = isset($source['related_id']) && $source['related_id'] !== '' ? (int)$source['related_id'] : null;

    if ($relatedTable === '' && $relatedId === null) {
        return [null, null];
    }
    if ($relatedTable === '' || $relatedId === null || $relatedId <= 0) {
        jsonError('related_table and related_id must be provided together.', 400);
    }

    return [validatedTable($relatedTable, 'related_table'), $relatedId];
}

function validatedReminderDate(array $source): ?string
{
    $rawReminder = trim($source['reminder_date'] ?? '');
    if ($rawReminder === '') {
        return null;
    }

    $normalized = str_replace('T', ' ', $rawReminder);
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

function validatedBody(array $source): string
{
    $rawBody = trim($source['body'] ?? '');
    if ($rawBody === '') {
        jsonError('Note body cannot be empty.', 400);
    }
    if (mb_strlen($rawBody) > NOTE_BODY_MAX_LEN) {
        jsonError('Note exceeds maximum length of ' . NOTE_BODY_MAX_LEN . ' characters.', 400);
    }

    return $rawBody;
}

function notes_action_list_records($conn): void
{
    $table = validatedTable(trim($_GET['table'] ?? ''), 'table');

    require_once __DIR__ . '/../../includes/config_store.php';
    $schema   = config_get('schema') ?? [];
    $tableCfg = $schema['tables'][$table];
    $pgSchema = $tableCfg['schema'] ?? 'public';

    $userRecordsCfg = config_get('user_records') ?? [];
    $configuredColumns = is_array($userRecordsCfg['columns'][$table] ?? null) ? $userRecordsCfg['columns'][$table] : [];
    $labelSql       = record_label_sql($tableCfg, $configuredColumns);

    $sql = sprintf(
        'SELECT id, %s AS label FROM %s.%s ORDER BY id DESC LIMIT %d',
        $labelSql,
        pg_ident($pgSchema),
        pg_ident($table),
        NOTE_RECORD_PICKER_LIMIT
    );
    $result = pg_query($conn, $sql);
    if (!$result) {
        error_log('api_notes notes_action_list_records failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $records = [];
    while ($row = pg_fetch_assoc($result)) {
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
    $result = pg_query_params($conn, $sql, [$userId]);
    if (!$result) {
        error_log('api_notes notes_action_list failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $notes = [];
    while ($row = pg_fetch_assoc($result)) {
        $row['related_id'] = $row['related_id'] !== null ? (int)$row['related_id'] : null;

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
    $result = pg_query_params($conn, $sql, [$userId, $noteBody, $relatedTable, $relatedId, $reminderDate]);
    if (!$result) {
        error_log('api_notes notes_action_add failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $inserted = pg_fetch_assoc($result);
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
    $result = pg_query_params($conn, $sql, [$noteBody, $relatedTable, $relatedId, $reminderDate, $id, $userId]);
    if (!$result) {
        error_log('api_notes notes_action_update failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    if (pg_num_rows($result) === 0) {
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
    $result = pg_query_params($conn, $sql, [$id, $userId]);
    if (!$result) {
        error_log('api_notes notes_action_delete failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    if (pg_affected_rows($result) === 0) {
        jsonError('Note not found.', 404);
    }

    log_user_action($conn, $userId, 'NOTE_DELETE', 'notes', $id);
    jsonSuccess(['deleted' => true]);
}
