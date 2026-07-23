<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// api/notes.php — Private user notepad (User menu > Notes), optionally linked to a
// record via related_table/related_id, with an optional reminder_date. Reminders are
// delivered by cron/cron_notifications.php into spw_users_notifications (the bell icon).
// Auth gate: session + UA enforcement; CSRF via X-CSRF-Token header (default os_api_bootstrap gate)
// match() action routing: list, add, update, delete — every query scoped to the caller's
// own user_id, never trusting a client-supplied one. sys_table('notes'); parameterized queries.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$conn = os_api_bootstrap();

const NOTE_BODY_MAX_LEN = 4000;
const NOTE_RECORD_PICKER_LIMIT = 500;

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    $body   = [];
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($method === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';
    }

    if ($action === '') {
        jsonError('Missing action.', 400);
    }

    match ($action) {
        'list'         => actionList($conn),
        'list_records' => actionListRecords($conn),
        'add'          => actionAdd($conn, $body),
        'update'       => actionUpdate($conn, $body),
        'delete'       => actionDelete($conn, $body),
        default        => jsonError("Unknown action: {$action}", 400),
    };
} catch (Throwable $e) {
    error_log('[api_notes] ' . $e->getMessage());
    jsonError('Internal server error.', 500);
}

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

// Validates an optional reminder_date (Y-m-d, today or later). Returns null when unset.
function validatedReminderDate(array $src): ?string
{
    $raw = trim($src['reminder_date'] ?? '');
    if ($raw === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $raw);
    if (!$date || $date->format('Y-m-d') !== $raw) {
        jsonError('reminder_date must be a valid date (YYYY-MM-DD).', 400);
    }
    if ($raw < date('Y-m-d')) {
        jsonError('reminder_date cannot be in the past.', 400);
    }

    return $raw;
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
// table+record dropdown pair (public/api/files.php actionGetRelatedRecords): given a
// table name, returns its most recent rows as {id, label}. Label columns come from the
// same heuristic as the "My records" panel (record_label_columns() in api_helpers.php)
// since spw_notes has no per-relation column config like the Files module does.
function actionListRecords($conn): void
{
    $table = validatedTable(trim($_GET['table'] ?? ''), 'table');

    require_once __DIR__ . '/../../includes/config_store.php';
    $schema   = config_get('schema') ?? [];
    $tableCfg = $schema['tables'][$table];
    $pgSchema = $tableCfg['schema'] ?? 'public';

    $userRecordsCfg = config_get('user_records') ?? [];
    $configuredCols = is_array($userRecordsCfg['columns'][$table] ?? null) ? $userRecordsCfg['columns'][$table] : [];
    $labelCols      = record_label_columns($tableCfg, $configuredCols);

    $escapedLabelCols = array_map('pg_ident', $labelCols);
    $labelSql = count($escapedLabelCols) > 1
        ? "CONCAT_WS(' - ', " . implode(', ', $escapedLabelCols) . ')'
        : $escapedLabelCols[0];

    $sql = sprintf(
        'SELECT id, %s AS label FROM %s.%s ORDER BY id DESC LIMIT %d',
        $labelSql,
        pg_ident($pgSchema),
        pg_ident($table),
        NOTE_RECORD_PICKER_LIMIT
    );
    $res = pg_query($conn, $sql);
    if (!$res) {
        error_log('api_notes actionListRecords failed: ' . pg_last_error($conn));
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

function actionList($conn): void
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
        error_log('api_notes actionList failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $notes = [];
    while ($row = pg_fetch_assoc($res)) {
        $row['related_id'] = $row['related_id'] !== null ? (int)$row['related_id'] : null;
        $notes[] = $row;
    }

    jsonSuccess(['notes' => $notes]);
}

function actionAdd($conn, array $body): void
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
        error_log('api_notes actionAdd failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $inserted = pg_fetch_assoc($res);
    log_user_action($conn, $userId, 'NOTE_ADD', 'notes', (int)$inserted['id']);

    jsonSuccess(['note' => [
        'id'            => (int)$inserted['id'],
        'body'          => $noteBody,
        'related_table' => $relatedTable,
        'related_id'    => $relatedId,
        'reminder_date' => $reminderDate,
        'created_at'    => $inserted['created_at'],
        'updated_at'    => null,
    ]], 201);
}

function actionUpdate($conn, array $body): void
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
        error_log('api_notes actionUpdate failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    if (pg_num_rows($res) === 0) {
        jsonError('Note not found.', 404);
    }

    log_user_action($conn, $userId, 'NOTE_UPDATE', 'notes', $id);
    jsonSuccess(['updated' => true]);
}

function actionDelete($conn, array $body): void
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
        error_log('api_notes actionDelete failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    if (pg_affected_rows($res) === 0) {
        jsonError('Note not found.', 404);
    }

    log_user_action($conn, $userId, 'NOTE_DELETE', 'notes', $id);
    jsonSuccess(['deleted' => true]);
}
