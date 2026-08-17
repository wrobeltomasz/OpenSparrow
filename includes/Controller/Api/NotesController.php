<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Service\ApiRequest;
use App\Service\AppContext;
use DateTime;
use PgSql\Connection;

final class NotesController
{
    private const BODY_MAX_LENGTH = 4000;

    private const RECORD_PICKER_LIMIT = 500;

    private readonly Connection $conn;

    private readonly array $body;

    private readonly PhpRequest $request;

    private readonly SessionInterface $session;

    public function __construct(AppContext $context, private readonly ApiRequest $api)
    {
        $this->conn    = $context->connection();
        $this->body    = $api->bodyAll();
        $this->request = $context->request();
        $this->session = $context->session();
    }

    public function handle(): void
    {
        os_api_dispatch($this->api->action, [
            'list'         => fn() => $this->listNotes(),
            'list_records' => fn() => $this->listRecords(),
            'add'          => fn() => $this->addNote(),
            'update'       => fn() => $this->updateNote(),
            'delete'       => fn() => $this->deleteNote(),
        ], 'api_notes');
    }

    private function validatedRelation(array $source): array
    {
        $relatedTable = trim($source['related_table'] ?? '');
        $relatedId    = isset($source['related_id']) && $source['related_id'] !== ''
            ? (int)$source['related_id']
            : null;

        if ($relatedTable === '' && $relatedId === null) {
            return [null, null];
        }
        if ($relatedTable === '' || $relatedId === null || $relatedId <= 0) {
            jsonError('related_table and related_id must be provided together.', 400);
        }

        return [validatedTable($relatedTable, 'related_table'), $relatedId];
    }

    private function validatedReminderDate(array $source): ?string
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

    private function validatedBody(array $source): string
    {
        $rawBody = trim($source['body'] ?? '');
        if ($rawBody === '') {
            jsonError('Note body cannot be empty.', 400);
        }
        if (mb_strlen($rawBody) > self::BODY_MAX_LENGTH) {
            jsonError('Note exceeds maximum length of ' . self::BODY_MAX_LENGTH . ' characters.', 400);
        }

        return $rawBody;
    }

    private function listRecords(): void
    {
        $table = validatedTable(trim($this->request->query('table')), 'table');

        require_once __DIR__ . '/../../config_store.php';
        $schema   = config_get('schema') ?? [];
        $tableConfig = $schema['tables'][$table];
        $pgSchema = $tableConfig['schema'] ?? 'public';

        $userRecordsConfig = config_get('user_records') ?? [];
        $configuredColumns = is_array($userRecordsConfig['columns'][$table] ?? null)
            ? $userRecordsConfig['columns'][$table]
            : [];
        $labelSql       = record_label_sql($tableConfig, $configuredColumns);

        $sql = sprintf(
            'SELECT id, %s AS label FROM %s.%s ORDER BY id DESC LIMIT %d',
            $labelSql,
            pg_ident($pgSchema),
            pg_ident($table),
            self::RECORD_PICKER_LIMIT
        );
        $result = pg_query($this->conn, $sql);
        if (!$result) {
            error_log('api_notes notes_action_list_records failed: ' . pg_last_error($this->conn));
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

    private function listNotes(): void
    {
        $userId = $this->session->userId();
        $sql = "
        SELECT id, body, related_table, related_id, reminder_date, created_at, updated_at
        FROM " . sys_table('notes') . "
        WHERE user_id = \$1 AND deleted_at IS NULL
        ORDER BY reminder_date NULLS LAST, created_at DESC
    ";
        $result = pg_query_params($this->conn, $sql, [$userId]);
        if (!$result) {
            error_log('api_notes notes_action_list failed: ' . pg_last_error($this->conn));
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

    private function addNote(): void
    {
        $body = $this->body;

        $userId = $this->session->userId();
        $noteBody = $this->validatedBody($body);
        [$relatedTable, $relatedId] = $this->validatedRelation($body);
        $reminderDate = $this->validatedReminderDate($body);

        $sql = "
        INSERT INTO " . sys_table('notes') . "
            (user_id, body, related_table, related_id, reminder_date)
        VALUES (\$1, \$2, \$3, \$4, \$5)
        RETURNING id, created_at
    ";
        $result = pg_query_params(
            $this->conn,
            $sql,
            [$userId, $noteBody, $relatedTable, $relatedId, $reminderDate]
        );
        if (!$result) {
            error_log('api_notes notes_action_add failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $inserted = pg_fetch_assoc($result);
        log_user_action($this->conn, $userId, 'NOTE_ADD', 'notes', (int)$inserted['id']);

        jsonSuccess(['note' => [
            'id'            => (int)$inserted['id'],
            'body'          => $noteBody,
            'related_table' => $relatedTable,
            'related_id'    => $relatedId,
            'reminder_date' => $reminderDate !== null
                ? substr(str_replace(' ', 'T', $reminderDate), 0, 16)
                : null,
            'created_at'    => $inserted['created_at'],
            'updated_at'    => null,
        ]], 201);
    }

    private function updateNote(): void
    {
        $body = $this->body;

        $userId = $this->session->userId();
        $id     = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            jsonError('id is required.', 400);
        }

        $noteBody = $this->validatedBody($body);
        [$relatedTable, $relatedId] = $this->validatedRelation($body);
        $reminderDate = $this->validatedReminderDate($body);

        $sql = "
        UPDATE " . sys_table('notes') . "
        SET body = \$1, related_table = \$2, related_id = \$3, reminder_date = \$4, updated_at = NOW()
        WHERE id = \$5 AND user_id = \$6 AND deleted_at IS NULL
        RETURNING id
    ";
        $result = pg_query_params(
            $this->conn,
            $sql,
            [$noteBody, $relatedTable, $relatedId, $reminderDate, $id, $userId]
        );
        if (!$result) {
            error_log('api_notes notes_action_update failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }
        if (pg_num_rows($result) === 0) {
            jsonError('Note not found.', 404);
        }

        log_user_action($this->conn, $userId, 'NOTE_UPDATE', 'notes', $id);
        jsonSuccess(['updated' => true]);
    }

    private function deleteNote(): void
    {
        $body = $this->body;

        $userId = $this->session->userId();
        $id     = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            jsonError('id is required.', 400);
        }

        $sql = "
        UPDATE " . sys_table('notes') . "
        SET deleted_at = NOW()
        WHERE id = \$1 AND user_id = \$2 AND deleted_at IS NULL
    ";
        $result = pg_query_params($this->conn, $sql, [$id, $userId]);
        if (!$result) {
            error_log('api_notes notes_action_delete failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }
        if (pg_affected_rows($result) === 0) {
            jsonError('Note not found.', 404);
        }

        log_user_action($this->conn, $userId, 'NOTE_DELETE', 'notes', $id);
        jsonSuccess(['deleted' => true]);
    }
}
