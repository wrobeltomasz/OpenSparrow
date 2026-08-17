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
use PgSql\Connection;

final class OwnersController
{
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
            'get'      => fn() => $this->currentOwner(),
            'history'  => fn() => $this->ownerHistory(),
            'editors'  => fn() => $this->eligibleOwners(),
            'mine'     => fn() => $this->myRecords(),
            'set'      => fn() => $this->setOwner(),
            'mass_set' => fn() => $this->massSetOwner(),
        ], 'api_owners');
    }

    private function currentOwner(): void
    {
        requireLogin();

        $table    = validatedTable(trim($this->request->query('table')));
        $recordId = (int) $this->request->query('id', '0');

        if ($recordId <= 0) {
            jsonError('id must be a positive integer.', 400);
        }

        $sql = "
        SELECT o.owner_id, u.username, u.avatar_id, o.changed_at
        FROM " . sys_table('record_owners') . " o
        LEFT JOIN " . sys_table('users') . " u ON u.id = o.owner_id
        WHERE o.table_name = \$1 AND o.record_id = \$2 AND o.is_current = true
    ";

        $result = pg_query_params($this->conn, $sql, [$table, $recordId]);
        if (!$result) {
            error_log('[api_owners owners_action_get] ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $row = pg_fetch_assoc($result);
        if (!$row) {
            jsonSuccess(['owner' => null]);
        }

        $owner = [
            'id'         => $row['owner_id'] !== null ? (int)$row['owner_id'] : null,
            'username'   => $row['username'],
            'avatar_id'  => $row['avatar_id'] !== null ? (int)$row['avatar_id'] : null,
            'changed_at' => $row['changed_at'],
        ];

        jsonSuccess(['owner' => $owner]);
    }

    private function ownerHistory(): void
    {
        requireLogin();

        $table    = validatedTable(trim($this->request->query('table')));
        $recordId = (int) $this->request->query('id', '0');

        if ($recordId <= 0) {
            jsonError('id must be a positive integer.', 400);
        }

        $sql = "
        SELECT o.owner_id, u.username, o.changed_at, cb.username AS changed_by_name
        FROM " . sys_table('record_owners') . " o
        LEFT JOIN " . sys_table('users') . " u  ON u.id  = o.owner_id
        LEFT JOIN " . sys_table('users') . " cb ON cb.id = o.changed_by
        WHERE o.table_name = \$1 AND o.record_id = \$2
        ORDER BY o.changed_at DESC
    ";

        $result = pg_query_params($this->conn, $sql, [$table, $recordId]);
        if (!$result) {
            error_log('[api_owners owners_action_history] ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = [
                'owner_id'        => $row['owner_id'] !== null ? (int)$row['owner_id'] : null,
                'username'        => $row['username'],
                'changed_at'      => $row['changed_at'],
                'changed_by_name' => $row['changed_by_name'],
            ];
        }

        jsonSuccess(['history' => $rows]);
    }

    private function eligibleOwners(): void
    {
        requireLogin();

        $sql = "
        SELECT id, username
        FROM " . sys_table('users') . "
        WHERE is_active = true AND role IN ('editor', 'admin')
        ORDER BY username
    ";

        $result = pg_query($this->conn, $sql);
        if (!$result) {
            error_log('[api_owners owners_action_editors] ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $users = [];
        while ($row = pg_fetch_assoc($result)) {
            $users[] = ['id' => (int)$row['id'], 'username' => $row['username']];
        }

        jsonSuccess(['users' => $users]);
    }

    private function myRecords(): void
    {
        requireLogin();

        $userId = $this->session->userId();

        $sql = "
        SELECT table_name, record_id, changed_at
        FROM " . sys_table('record_owners') . "
        WHERE owner_id = \$1 AND is_current = true
        ORDER BY table_name, changed_at DESC, record_id DESC
    ";

        $result = pg_query_params($this->conn, $sql, [$userId]);
        if (!$result) {
            error_log('[api_owners owners_action_mine] ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        require_once __DIR__ . '/../../config_store.php';
        $userRecordsCfg  = config_get('user_records') ?? [];
        $configuredColumns  = is_array($userRecordsCfg['columns'] ?? null) ? $userRecordsCfg['columns'] : [];
        $limit           = (int)($userRecordsCfg['limit'] ?? 20);

        $byTable    = [];
        $assignedAt = [];
        while ($row = pg_fetch_assoc($result)) {
            $tableName = $row['table_name'];
            if ($limit > 0 && count($byTable[$tableName] ?? []) >= $limit) {
                continue;
            }
            $recordId = (int)$row['record_id'];
            $byTable[$tableName][] = $recordId;
            $assignedAt[$tableName . '#' . $recordId] = $row['changed_at'];
        }

        $schema = config_get('schema') ?? [];

        $records = [];
        foreach ($byTable as $tableName => $ids) {
            $tableCfg = $schema['tables'][$tableName] ?? null;

            if ($tableCfg === null || !empty($tableCfg['hidden'])) {
                continue;
            }

            if (!user_can_access_table($tableName)) {
                continue;
            }

            $pgSchema = $tableCfg['schema'] ?? 'public';
            $rowIdsArray = '{' . implode(',', $ids) . '}';
            $labelSql = record_label_sql($tableCfg, $configuredColumns[$tableName] ?? []);

            $rowsSql = sprintf(
                'SELECT id, %s AS label FROM %s.%s WHERE id = ANY($1::int[])',
                $labelSql,
                pg_ident($pgSchema),
                pg_ident($tableName)
            );

            $rowsResult = pg_query_params($this->conn, $rowsSql, [$rowIdsArray]);
            if (!$rowsResult) {
                error_log('[api_owners owners_action_mine] ' . pg_last_error($this->conn));
                continue;
            }

            $tableDisplay = to_display_name($tableCfg);
            while ($row = pg_fetch_assoc($rowsResult)) {
                $recordId = (int)$row['id'];
                $label    = trim((string)($row['label'] ?? ''));
                $records[] = [
                    'table'         => $tableName,
                    'table_display' => $tableDisplay,
                    'id'            => $recordId,
                    'label'         => $label !== '' ? $label : ('#' . $recordId),
                    'assigned_at'   => $assignedAt[$tableName . '#' . $recordId] ?? null,
                ];
            }
        }

        usort(
            $records,
            fn($first, $second) => strcmp((string)$second['assigned_at'], (string)$first['assigned_at'])
        );

        jsonSuccess(['records' => $records]);
    }

    private function massSetOwner(): void
    {
        $body = $this->body;

        requireWrite();
        os_require_csrf('body', $body);

        $table   = validatedTable(trim($body['table'] ?? ''));
        $ownerId = (int)($body['owner_id'] ?? 0);

        if ($ownerId <= 0) {
            jsonError('owner_id must be a positive integer.', 400);
        }

        $checkResult = pg_query_params(
            $this->conn,
            "SELECT id FROM " . sys_table('users') .
            " WHERE id = \$1 AND is_active = true AND role IN ('editor', 'admin')",
            [$ownerId]
        );
        if (!$checkResult || pg_num_rows($checkResult) === 0) {
            jsonError('Invalid owner: user not found or does not have editor access.', 400);
        }

        $rawIds = $body['row_ids'] ?? [];
        if (!is_array($rawIds)) {
            jsonError('row_ids must be an array.', 400);
        }
        $rowIds = [];
        foreach ($rawIds as $id) {
            $validatedId = filter_var($id, FILTER_VALIDATE_INT);
            if ($validatedId !== false && $validatedId > 0) {
                $rowIds[] = $validatedId;
            }
        }
        $rowIds = array_values(array_unique($rowIds));

        if (empty($rowIds)) {
            jsonError('No rows selected.', 400);
        }

        $changedBy = $this->session->userId();
        $ownersTable         = sys_table('record_owners');
        $rowIdsArray  = '{' . implode(',', array_map('intval', $rowIds)) . '}';

        @pg_query($this->conn, 'BEGIN');

        $result = @pg_query_params(
            $this->conn,
            "UPDATE $ownersTable SET is_current = false
         WHERE table_name = \$1 AND record_id = ANY(\$2::int[]) AND is_current = true",
            [$table, $rowIdsArray]
        );
        if (!$result) {
            @pg_query($this->conn, 'ROLLBACK');
            jsonError('Database error.', 500);
        }

        $insertResult = @pg_query_params(
            $this->conn,
            "INSERT INTO $ownersTable (table_name, record_id, owner_id, changed_by, is_current)
         SELECT \$1, unnest(\$2::int[]), \$3, \$4, true",
            [$table, $rowIdsArray, $ownerId, $changedBy]
        );
        if (!$insertResult) {
            @pg_query($this->conn, 'ROLLBACK');
            jsonError('Database error.', 500);
        }

        $affected = pg_affected_rows($insertResult);
        @pg_query($this->conn, 'COMMIT');

        log_user_action($this->conn, $changedBy, 'MASS_OWNER', $table, null);

        jsonSuccess(['updated' => $affected]);
    }

    private function setOwner(): void
    {
        $body = $this->body;

        requireWrite();
        os_require_csrf('body', $body);

        $table    = validatedTable(trim($body['table'] ?? ''));
        $recordId = (int)($body['record_id'] ?? 0);
        $ownerId  = (int)($body['owner_id'] ?? 0);

        if ($recordId <= 0) {
            jsonError('record_id must be a positive integer.', 400);
        }
        if ($ownerId <= 0) {
            jsonError('owner_id must be a positive integer.', 400);
        }

        $checkResult = pg_query_params(
            $this->conn,
            "SELECT id FROM " . sys_table('users')
            . " WHERE id = \$1 AND is_active = true AND role IN ('editor', 'admin')",
            [$ownerId]
        );
        if (!$checkResult || pg_num_rows($checkResult) === 0) {
            jsonError('Invalid owner: user not found or does not have editor access.', 400);
        }

        $changedBy = $this->session->userId();
        set_record_owner($this->conn, $table, $recordId, $ownerId, $changedBy);

        jsonSuccess(['changed' => true]);
    }
}
